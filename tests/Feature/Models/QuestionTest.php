<?php

use App\Models\Question;
use App\Models\QuestionOption;

/** A question with $options options, all translated in ar + en unless overridden. */
function questionWith(array $text = ['ar' => 'سؤال', 'en' => 'Question'], int $options = 4): Question
{
    $question = Question::factory()->create(['text' => $text]);
    for ($i = 1; $i <= $options; $i++) {
        QuestionOption::factory()->for($question)->create([
            'text' => ['ar' => "خيار {$i}", 'en' => "Option {$i}"],
            'is_correct' => $i === 1,
            'sort_order' => $i,
        ]);
    }

    return $question;
}

describe('translatedIn', function () {
    it('includes questions translated everywhere', function () {
        $complete = questionWith();

        expect(Question::translatedIn(['ar', 'en'])->pluck('id')->all())->toBe([$complete->id]);
    });

    it('excludes questions missing question text in a locale', function () {
        questionWith(['ar' => 'سؤال']);
        questionWith(['en' => 'Question']);
        questionWith(['ar' => 'سؤال', 'en' => '']);

        expect(Question::translatedIn(['ar', 'en'])->count())->toBe(0);
    });

    it('excludes questions with any option missing a translation', function () {
        $question = questionWith();
        $option = $question->options()->first();
        $option->forgetTranslation('text', 'en');
        $option->save();

        expect(Question::translatedIn(['ar', 'en'])->count())->toBe(0);
    });

    it('excludes questions with a blank option translation', function () {
        $question = questionWith();
        $option = $question->options()->first();
        $option->setTranslation('text', 'en', '');
        $option->save();

        expect(Question::translatedIn(['ar', 'en'])->count())->toBe(0);
    });

    it('only checks the requested locales', function () {
        $arabicOnly = questionWith(['ar' => 'سؤال']);
        $arabicOnly->options()->each(fn (QuestionOption $option) => $option->forgetTranslation('text', 'en')->save());

        expect(Question::translatedIn(['ar'])->pluck('id')->all())->toBe([$arabicOnly->id])
            ->and(Question::translatedIn(['ar', 'en'])->count())->toBe(0);
    });

    it('excludes questions without options', function () {
        questionWith(options: 0);

        expect(Question::translatedIn(['ar', 'en'])->count())->toBe(0);
    });

    it('agrees with the in-memory isTranslatedIn check', function () {
        $complete = questionWith();
        $partial = questionWith(['ar' => 'سؤال']);

        expect($complete->load('options')->isTranslatedIn(['ar', 'en']))->toBeTrue()
            ->and($partial->load('options')->isTranslatedIn(['ar', 'en']))->toBeFalse();
    });
});

it('prunes blank translations when saving', function () {
    $question = questionWith();
    $question->setTranslation('text', 'en', '');
    $question->save();

    expect($question->fresh()->getTranslations('text'))->toBe(['ar' => 'سؤال'])
        ->and(Question::whereNotNull('text->en')->count())->toBe(0);
});

it('scopes to active questions', function () {
    $active = Question::factory()->create();
    Question::factory()->inactive()->create();

    expect(Question::active()->pluck('id')->all())->toBe([$active->id]);
});

it('returns the correct option', function () {
    $question = questionWith();

    expect($question->correctOption->is_correct)->toBeTrue()
        ->and($question->correctOption->getTranslation('text', 'en'))->toBe('Option 1');
});

it('soft deletes', function () {
    $question = questionWith();
    $question->delete();

    expect(Question::count())->toBe(0)->and(Question::withTrashed()->count())->toBe(1);
});
