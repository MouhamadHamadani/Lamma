<?php

use App\Enums\Difficulty;
use App\Filament\Resources\Questions\Pages\CreateQuestion;
use App\Filament\Resources\Questions\Pages\EditQuestion;
use App\Filament\Resources\Questions\Pages\ListQuestions;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Question;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->category = Category::factory()->create();
});

/** @return list<array<string, mixed>> */
function options(int $count, int $correct = 1): array
{
    return collect(range(1, $count))->map(fn (int $i) => [
        'text' => ['ar' => "خيار {$i}", 'en' => "Option {$i}"],
        'is_correct' => $i <= $correct,
    ])->all();
}

function submitQuestion(array $options, array $overrides = []): Testable
{
    return Livewire::test(CreateQuestion::class)
        ->fillForm([
            'category_id' => test()->category->id,
            'difficulty' => Difficulty::Easy,
            'text' => ['ar' => 'ما هذا؟', 'en' => 'What is this?'],
            'is_active' => true,
            'options' => $options,
            ...$overrides,
        ])
        ->call('create');
}

describe('options validation', function () {
    it('saves a question with 4 options and exactly one correct', function () {
        submitQuestion(options(4))->assertHasNoFormErrors();

        $question = Question::with('options')->sole();
        expect($question->options)->toHaveCount(4)
            ->and($question->options->where('is_correct', true))->toHaveCount(1)
            ->and($question->options->first()->getTranslations('text'))->toBe(['ar' => 'خيار 1', 'en' => 'Option 1'])
            ->and($question->getTranslations('text'))->toBe(['ar' => 'ما هذا؟', 'en' => 'What is this?']);
    });

    it('accepts 5 options', function () {
        submitQuestion(options(5))->assertHasNoFormErrors();

        expect(Question::sole()->options)->toHaveCount(5);
    });

    it('rejects fewer than 4 options', function () {
        submitQuestion(options(3))->assertHasFormErrors(['options']);

        expect(Question::count())->toBe(0);
    });

    it('rejects more than 5 options', function () {
        submitQuestion(options(6))->assertHasFormErrors(['options']);

        expect(Question::count())->toBe(0);
    });

    it('rejects a question with no correct option', function () {
        submitQuestion(options(4, correct: 0))->assertHasFormErrors(['options']);

        expect(Question::count())->toBe(0);
    });

    it('rejects a question with more than one correct option', function () {
        submitQuestion(options(4, correct: 2))->assertHasFormErrors(['options']);

        expect(Question::count())->toBe(0);
    });
});

it('requires question text in at least one language', function () {
    submitQuestion(options(4), ['text' => ['ar' => '', 'en' => '']])
        ->assertHasFormErrors(['text.ar', 'text.en']);
});

it('allows a draft translated into one language only', function () {
    submitQuestion(options(4), ['text' => ['ar' => 'ما هذا؟', 'en' => '']])->assertHasNoFormErrors();

    expect(Question::sole()->getTranslations('text'))->toBe(['ar' => 'ما هذا؟']);
});

it('filters the list down to questions missing a translation', function () {
    $complete = Question::factory()->withOptions()->create();
    $missing = Question::factory()->withOptions()->create(['text' => ['ar' => 'بدون إنجليزي']]);

    Livewire::test(ListQuestions::class)
        ->assertCanSeeTableRecords([$complete, $missing])
        ->filterTable('missing_translation', true)
        ->assertCanSeeTableRecords([$missing])
        ->assertCanNotSeeTableRecords([$complete]);
});

it('loads both languages when editing and drops a cleared translation on save', function () {
    $question = Question::factory()->withOptions()->create();
    $arabic = $question->getTranslation('text', 'ar');

    Livewire::test(EditQuestion::class, ['record' => $question->getRouteKey()])
        ->assertFormSet(['text.ar' => $arabic, 'text.en' => $question->getTranslation('text', 'en')])
        ->fillForm(['text' => ['ar' => $arabic, 'en' => '']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($question->fresh()->getTranslations('text'))->toBe(['ar' => $arabic])
        ->and($question->options()->count())->toBe(4);
});
