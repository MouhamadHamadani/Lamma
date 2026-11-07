<?php

use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Filament\Resources\Questions\Pages\CreateQuestion;
use App\Filament\Resources\Questions\Pages\EditQuestion;
use App\Filament\Resources\Questions\Pages\ListQuestions;
use App\Game\QuestionImage;
use App\Game\QuestionImageException;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Question;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->category = Category::factory()->create();
});

/** @return list<array<string, mixed>> $count translated options, the first $correct of them correct */
function typedOptions(int $count, int $correct = 1): array
{
    return collect(range(1, $count))->map(fn (int $i) => ['text' => ['ar' => "خيار {$i}", 'en' => "Option {$i}"], 'is_correct' => $i <= $correct])->all();
}

/** Markup that must never survive a picture being re-encoded. (Built from pieces: a file that spells it out whole gets quarantined by antivirus.) */
function hiddenPayload(): string
{
    return '<'.'?php echo 1; ?'.'><'.'scr'.'ipt>alert(1)</'.'scr'.'ipt>';
}

/** The bytes of a picture of this kind: a real one, or something pretending to be one. */
function pictureBytes(string $kind): string
{
    return match ($kind) {
        'jpeg' => jpegBytes(640, 480),
        'webp' => webpBytes(300, 200),
        'png' => pngBytes(300, 200),
        'gif' => gifBytes(),
        'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100">'.hiddenPayload().'</svg>',
        'php' => '<'.'?php echo 1;',
        'text' => 'not an image at all',
        'tiny' => jpegBytes(20, 20),
        'wide' => jpegBytes(4200, 100),
        'huge' => jpegBytes(600, 600).str_repeat('0', 2 * 1024 * 1024),
    };
}

/** The create form filled for this type; $state overrides any field. */
function typedForm(string $type, array $state = []): Testable
{
    return Livewire::test(CreateQuestion::class)->fillForm([
        'type' => $type,
        'category_id' => test()->category->id,
        'difficulty' => Difficulty::Easy,
        'text' => ['ar' => 'ما هذا؟', 'en' => 'What is this?'],
        ...$state,
    ]);
}

describe('the form shows the right fields for each type', function () {
    it('shows the four options for a multiple-choice question, and nothing of the other types', function () {
        Livewire::test(CreateQuestion::class)
            ->assertFormFieldIsVisible('options')->assertFormFieldIsHidden('true_false_answer')->assertFormFieldIsHidden('answer_number')->assertFormFieldIsHidden('tolerance')
            ->assertFormFieldIsVisible('image_path')->assertFormFieldIsVisible('explanation.ar')->assertFormFieldIsVisible('explanation.en');
    });

    it('shows only True / False for a true/false question', function () {
        typedForm('true_false')
            ->assertFormFieldIsVisible('true_false_answer')->assertFormFieldIsHidden('options')->assertFormFieldIsHidden('answer_number')->assertFormFieldIsHidden('tolerance')
            ->assertFormFieldIsVisible('image_path');
    });

    it('shows only the number and the tolerance for a closest-number question', function () {
        typedForm('closest_number')
            ->assertFormFieldIsVisible('answer_number')->assertFormFieldIsVisible('tolerance')->assertFormFieldIsHidden('options')->assertFormFieldIsHidden('true_false_answer')
            ->assertFormFieldIsVisible('image_path');
    });

    it('offers every type in the selector, multiple choice first', function () {
        expect(QuestionType::cases())->toHaveCount(3);
        Livewire::test(CreateQuestion::class)->assertFormFieldExists('type')->assertFormSet(['type' => QuestionType::MultipleChoice]);
    });
});

describe('creating a true/false question', function () {
    it('makes the two options from the one choice, True first', function (string $choice, string $correct) {
        typedForm('true_false', ['true_false_answer' => $choice])->call('create')->assertHasNoFormErrors();

        $question = Question::with('options')->sole();
        expect($question->type)->toBe(QuestionType::TrueFalse)
            ->and($question->options->map(fn ($o) => $o->getTranslation('text', 'en'))->all())->toBe(['True', 'False'])
            ->and($question->options->firstWhere('is_correct', true)->getTranslation('text', 'en'))->toBe($correct)
            ->and($question->answer_number)->toBeNull()->and($question->tolerance)->toBeNull()
            ->and($question->load('options')->problems())->toBe([]);
    })->with([['true', 'True'], ['false', 'False']]);

    it('needs the choice, and does not ask for four options', function () {
        typedForm('true_false')->call('create')->assertHasFormErrors(['true_false_answer' => 'required']);

        expect(Question::count())->toBe(0);
    });
});

describe('creating a closest-number question', function () {
    it('keeps the number, the tolerance and no options', function () {
        typedForm('closest_number', ['answer_number' => 1969, 'tolerance' => 10])->call('create')->assertHasNoFormErrors();

        $question = Question::with('options')->sole();
        expect($question->type)->toBe(QuestionType::ClosestNumber)->and($question->answer_number)->toBe(1969.0)->and($question->tolerance)->toBe(10.0)
            ->and($question->options)->toHaveCount(0)->and($question->problems())->toBe([]);
    });

    it('fills in a quarter of the number, at least 1, when the tolerance is left empty', function () {
        typedForm('closest_number', ['answer_number' => 150])->call('create')->assertHasNoFormErrors();

        expect(Question::sole()->tolerance)->toBe(37.5);
    });

    it('needs the number, and a tolerance above zero', function () {
        typedForm('closest_number')->call('create')->assertHasFormErrors(['answer_number' => 'required']);
        typedForm('closest_number', ['answer_number' => 5, 'tolerance' => 0])->call('create')->assertHasFormErrors(['tolerance']);
        typedForm('closest_number', ['answer_number' => 5, 'tolerance' => -2])->call('create')->assertHasFormErrors(['tolerance']);
        typedForm('closest_number', ['answer_number' => 'five'])->call('create')->assertHasFormErrors(['answer_number']);

        expect(Question::count())->toBe(0);
    });

    it('does not keep options left in the form from another type', function () {
        typedForm('closest_number', ['answer_number' => 7, 'options' => typedOptions(4)])->call('create')->assertHasNoFormErrors();

        expect(Question::sole()->options()->count())->toBe(0);
    });
});

describe('a multiple-choice question is as it was', function () {
    it('still needs its options', function () {
        typedForm('multiple_choice', ['options' => typedOptions(3)])->call('create')->assertHasFormErrors(['options']);
        typedForm('multiple_choice', ['options' => typedOptions(4)])->call('create')->assertHasNoFormErrors();

        expect(Question::sole()->options)->toHaveCount(4)->and(Question::sole()->type)->toBe(QuestionType::MultipleChoice);
    });
});

describe('changing a question\'s type', function () {
    it('shows which of True and False is correct when a true/false question is opened', function () {
        $false = Question::factory()->trueFalse(false)->create();
        $true = Question::factory()->trueFalse(true)->create();

        Livewire::test(EditQuestion::class, ['record' => $false->getRouteKey()])->assertFormSet(['type' => QuestionType::TrueFalse, 'true_false_answer' => 'false']);
        Livewire::test(EditQuestion::class, ['record' => $true->getRouteKey()])->assertFormSet(['true_false_answer' => 'true']);
    });

    it('can flip a true/false answer, still two options', function () {
        $question = Question::factory()->trueFalse(true)->create();

        Livewire::test(EditQuestion::class, ['record' => $question->getRouteKey()])->fillForm(['true_false_answer' => 'false'])->call('save')->assertHasNoFormErrors();

        $options = $question->fresh()->options;
        expect($options)->toHaveCount(2)->and($options->firstWhere('is_correct', true)->getTranslation('text', 'en'))->toBe('False');
    });

    it('turns a multiple-choice question into a true/false one: the four options become True and False', function () {
        $question = Question::factory()->withOptions()->create();

        Livewire::test(EditQuestion::class, ['record' => $question->getRouteKey()])
            ->fillForm(['type' => 'true_false', 'true_false_answer' => 'true'])->call('save')->assertHasNoFormErrors();

        $question = $question->fresh()->load('options');
        expect($question->type)->toBe(QuestionType::TrueFalse)->and($question->options)->toHaveCount(2)->and($question->problems())->toBe([]);
    });

    it('turns a multiple-choice question into a number one: the options go, the number stays', function () {
        $question = Question::factory()->withOptions()->create();

        Livewire::test(EditQuestion::class, ['record' => $question->getRouteKey()])
            ->fillForm(['type' => 'closest_number', 'answer_number' => 88, 'tolerance' => 12])->call('save')->assertHasNoFormErrors();

        $question = $question->fresh()->load('options');
        expect($question->type)->toBe(QuestionType::ClosestNumber)->and($question->options)->toHaveCount(0)->and($question->answer_number)->toBe(88.0)->and($question->problems())->toBe([]);
    });

    it('turns a number question into a multiple-choice one and clears the number', function () {
        $question = Question::factory()->closestNumber(10, 2)->create();

        Livewire::test(EditQuestion::class, ['record' => $question->getRouteKey()])
            ->fillForm(['type' => 'multiple_choice', 'options' => typedOptions(4)])->call('save')->assertHasNoFormErrors();

        $question = $question->fresh()->load('options');
        expect($question->type)->toBe(QuestionType::MultipleChoice)->and($question->options)->toHaveCount(4)->and($question->answer_number)->toBeNull()->and($question->tolerance)->toBeNull();
    });
});

describe('the fun fact', function () {
    it('is optional, and has an Arabic and an English tab', function () {
        typedForm('closest_number', ['answer_number' => 5, 'tolerance' => 1])->call('create')->assertHasNoFormErrors();
        expect(Question::sole()->getTranslations('explanation'))->toBe([]);

        Question::query()->delete();
        typedForm('closest_number', ['answer_number' => 5, 'tolerance' => 1, 'explanation' => ['ar' => 'معلومة.', 'en' => 'A fact.']])->call('create')->assertHasNoFormErrors();
        expect(Question::sole()->getTranslations('explanation'))->toBe(['ar' => 'معلومة.', 'en' => 'A fact.']);
    });

    it('drops a language left empty and loads both when editing', function () {
        $question = Question::factory()->closestNumber(5, 1)->withFact('Hello.', 'مرحبا.')->create();

        Livewire::test(EditQuestion::class, ['record' => $question->getRouteKey()])
            ->assertFormSet(['explanation.ar' => 'مرحبا.', 'explanation.en' => 'Hello.'])
            ->fillForm(['explanation' => ['ar' => 'مرحبا.', 'en' => '']])->call('save')->assertHasNoFormErrors();

        expect($question->fresh()->getTranslations('explanation'))->toBe(['ar' => 'مرحبا.']);
    });

    it('stays short', function () {
        typedForm('closest_number', ['answer_number' => 5, 'tolerance' => 1, 'explanation' => ['en' => str_repeat('x', 301)]])->call('create')->assertHasFormErrors(['explanation.en']);
    });
});

describe('the picture', function () {
    it('is stored as WebP on the questions disk, whatever it was uploaded as, with no metadata', function (string $name, string $kind) {
        typedForm('closest_number', ['answer_number' => 5, 'tolerance' => 1, 'image_path' => upload(pictureBytes($kind), $name)])->call('create')->assertHasNoFormErrors();

        $path = Question::sole()->image_path;
        $stored = Storage::disk(QuestionImage::DISK)->get($path);
        expect($path)->toEndWith('.webp')->and((new finfo(FILEINFO_MIME_TYPE))->buffer($stored))->toBe('image/webp')->and($stored)->not->toContain('Exif');
    })->with([
        'a JPEG' => ['a.jpg', 'jpeg'],
        'a WebP' => ['a.webp', 'webp'],
        'a PNG' => ['a.png', 'png'],
    ]);

    it('is fitted to 1200 x 900 and never enlarged', function () {
        $big = (new QuestionImage)->process(jpegBytes(2400, 1200));
        $small = (new QuestionImage)->process(jpegBytes(300, 200));

        expect(getimagesizefromstring($big)[0])->toBe(1200)->and(getimagesizefromstring($big)[1])->toBe(600)
            ->and(getimagesizefromstring($small)[0])->toBe(300)->and(getimagesizefromstring($small)[1])->toBe(200);
    });

    it('refuses what is not a picture we accept, and saves nothing', function (string $name, string $kind) {
        typedForm('closest_number', ['answer_number' => 5, 'tolerance' => 1, 'image_path' => upload(pictureBytes($kind), $name)])->call('create')->assertHasFormErrors(['image_path']);

        expect(Question::count())->toBe(0)->and(Storage::disk(QuestionImage::DISK)->allFiles())->toBe([]);
    })->with([
        'a GIF' => ['a.gif', 'gif'],
        'an SVG' => ['a.svg', 'svg'],
        'a PHP file named like a picture' => ['a.jpg', 'php'],
        'text named like a picture' => ['a.png', 'text'],
        'a picture that is too small' => ['a.jpg', 'tiny'],
        'a picture that is too wide' => ['a.jpg', 'wide'],
        'a file that is too large' => ['a.jpg', 'huge'],
    ]);

    it('keeps nothing hidden after the picture: it is re-encoded, so a payload appended to it is gone', function () {
        $polyglot = jpegBytes(300, 300).hiddenPayload();

        $webp = (new QuestionImage)->process($polyglot);

        expect($webp)->not->toContain('<'.'?php')->not->toContain('<'.'scr'.'ipt')->and((new finfo(FILEINFO_MIME_TYPE))->buffer($webp))->toBe('image/webp');
    });

    it('is checked by what it is, not what it is called or what the browser says', function () {
        $images = new QuestionImage;

        expect(fn () => $images->validate(gifBytes()))->toThrow(QuestionImageException::class, 'JPEG, PNG or WebP')
            ->and(fn () => $images->validate(''))->toThrow(QuestionImageException::class)
            ->and(fn () => $images->validate(jpegBytes(10, 10)))->toThrow(QuestionImageException::class, 'too small')
            ->and($images->validate(jpegBytes(640, 480)))->toBe([640, 480]);
    });

    it('is shown again when editing', function () {
        $question = Question::factory()->closestNumber(5, 1)->create();
        $first = app(QuestionImage::class)->store(jpegBytes(400, 300));
        $question->update(['image_path' => $first]);

        Livewire::test(EditQuestion::class, ['record' => $question->getRouteKey()])->assertFormSet(['image_path' => $first]);
        expect(Storage::disk(QuestionImage::DISK)->exists($first))->toBeTrue();
    });
});

describe('the question list', function () {
    it('shows the type, filters by it and by a picture, and says which questions are complete', function () {
        $choice = Question::factory()->withOptions()->create();
        $trueFalse = Question::factory()->trueFalse()->create();
        $number = Question::factory()->closestNumber(5, 1)->create();
        $broken = Question::factory()->trueFalse()->create();
        $broken->options()->latest('id')->first()->delete();
        $picture = Question::factory()->withOptions()->withImage('x.webp')->create();

        Livewire::test(ListQuestions::class)
            ->assertCanSeeTableRecords([$choice, $trueFalse, $number, $broken, $picture])
            ->assertTableColumnExists('type')->assertTableColumnExists('complete')
            ->filterTable('type', 'true_false')->assertCanSeeTableRecords([$trueFalse, $broken])->assertCanNotSeeTableRecords([$choice, $number])
            ->removeTableFilters()->filterTable('type', 'closest_number')->assertCanSeeTableRecords([$number])->assertCanNotSeeTableRecords([$choice, $trueFalse])
            ->removeTableFilters()->filterTable('picture', 'with')->assertCanSeeTableRecords([$picture])->assertCanNotSeeTableRecords([$choice, $number])
            ->removeTableFilters()->filterTable('incomplete', true)->assertCanSeeTableRecords([$broken])->assertCanNotSeeTableRecords([$choice, $trueFalse, $number]);
    });

    it('does not call a number question missing its translation complete, or a well-formed one missing', function () {
        $good = Question::factory()->closestNumber(5, 1)->create();
        $untranslated = Question::factory()->closestNumber(5, 1)->create(['text' => ['en' => 'Only English?']]);

        Livewire::test(ListQuestions::class)
            ->filterTable('missing_translation', true)->assertCanSeeTableRecords([$untranslated])->assertCanNotSeeTableRecords([$good]);
        expect($good->load('options')->isTranslatedIn(['ar', 'en']))->toBeTrue();
    });
});
