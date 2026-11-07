<?php

use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Game\GameEngine;
use App\Game\QuestionImage;
use App\Game\QuestionPool;
use App\Game\QuestionReport;
use App\Game\RoomSettings;
use App\Models\Category;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

// (No Event::fake(): it would also fake the model events these tests are about. The broadcast driver is null in tests.)
beforeEach(fn () => Queue::fake());

/** How many questions a game on this category can draw. */
function playableIn(Category $category): int
{
    return app(QuestionPool::class)->available(new RoomSettings(categoryIds: [$category->id], questionCount: 1));
}

describe('the question types', function () {
    it('is multiple choice unless said otherwise, in the database and on a new instance', function () {
        expect((new Question)->type)->toBe(QuestionType::MultipleChoice)
            ->and(Question::factory()->create()->fresh()->type)->toBe(QuestionType::MultipleChoice);
    });

    it('says which types have options and which take a number', function () {
        expect(QuestionType::MultipleChoice->hasOptions())->toBeTrue()
            ->and(QuestionType::TrueFalse->hasOptions())->toBeTrue()
            ->and(QuestionType::ClosestNumber->hasOptions())->toBeFalse()
            ->and(QuestionType::ClosestNumber->takesNumber())->toBeTrue()
            ->and(QuestionType::TrueFalse->takesNumber())->toBeFalse();
    });

    it('has a label for each type, in both languages', function () {
        foreach (QuestionType::cases() as $type) {
            app()->setLocale('en');
            $english = $type->getLabel();
            app()->setLocale('ar');
            $arabic = $type->getLabel();

            expect($english)->not->toBe($arabic)->and($arabic)->toMatch('/\p{Arabic}/u');
        }
    });
});

describe('the tolerance of a closest-number question', function () {
    it('is a quarter of the answer, at least 1, when left empty', function (float $answer, float $tolerance) {
        expect(Question::defaultTolerance($answer))->toBe($tolerance);

        $question = Question::factory()->closestNumber($answer)->create();
        expect($question->fresh()->tolerance)->toBe($tolerance);
    })->with([
        'a year' => [1969.0, 492.25],
        'a distance' => [150.0, 37.5],
        'a small number' => [2.0, 1.0],
        'zero' => [0.0, 1.0],
        'a negative answer' => [-100.0, 25.0],
        'a big one' => [384400.0, 96100.0],
    ]);

    it('fills in a tolerance that is empty or not above 0, and keeps one that is', function () {
        $empty = Question::factory()->closestNumber(100, null)->create();
        $zero = Question::factory()->closestNumber(100, 0)->create();
        $own = Question::factory()->closestNumber(100, 7.5)->create();

        expect($empty->fresh()->tolerance)->toBe(25.0)->and($zero->fresh()->tolerance)->toBe(25.0)->and($own->fresh()->tolerance)->toBe(7.5);
    });

    it('does not invent a tolerance for a question that has no answer yet', function () {
        $question = Question::factory()->closestNumber(100)->create();
        $question->update(['answer_number' => null, 'tolerance' => null]);

        expect($question->fresh()->tolerance)->toBeNull();
    });
});

describe('the options of each type', function () {
    it('gives a true/false question True and False, in that order, with the right one marked', function (bool $answer) {
        $question = Question::factory()->trueFalse($answer)->create()->load('options');

        expect($question->type)->toBe(QuestionType::TrueFalse)
            ->and($question->options->map(fn ($o) => $o->getTranslation('text', 'en'))->all())->toBe(['True', 'False'])
            ->and($question->options->map(fn ($o) => $o->getTranslation('text', 'ar'))->all())->toBe(['صحيح', 'خطأ'])
            ->and($question->options->pluck('is_correct')->all())->toBe([$answer, ! $answer])
            ->and($question->options->pluck('sort_order')->all())->toBe([1, 2]);
    })->with([true, false]);

    it('can turn a question into the other answer without piling up options', function () {
        $question = Question::factory()->trueFalse(true)->create();

        $question->syncTrueFalse(false);
        $question->syncTrueFalse(false);

        $options = $question->fresh()->options;
        expect($options)->toHaveCount(2)->and($options->firstWhere('is_correct', true)->getTranslation('text', 'en'))->toBe('False');
    });

    it('always shows a true/false question as True then False, shuffles a multiple-choice one, and gives a closest-number one nothing', function () {
        $trueFalse = Question::factory()->trueFalse(false)->create()->load('options');
        $choice = Question::factory()->withOptions(4)->create()->load('options');
        $number = Question::factory()->closestNumber()->create()->load('options');

        $orders = collect(range(1, 40))->map(fn () => $choice->newOptionOrder());
        foreach (range(1, 20) as $ignored) {
            expect($trueFalse->newOptionOrder())->toBe($trueFalse->options->pluck('id')->all());
        }

        expect($orders->every(fn ($order) => collect($order)->sort()->values()->all() === $choice->options->pluck('id')->sort()->values()->all()))->toBeTrue()
            ->and($orders->unique(fn ($order) => implode(',', $order))->count())->toBeGreaterThan(1)
            ->and($number->newOptionOrder())->toBe([]);
    });

    it('stores that order when a game starts: True first for true/false, nothing for a number', function () {
        $room = typedRoom(['multiple_choice', 'true_false', 'closest_number']);
        startGame($room);

        $byType = $room->roomQuestions()->with('question.options')->get()->keyBy(fn ($rq) => $rq->question->type->value);

        expect($byType['true_false']->option_order)->toBe($byType['true_false']->question->options->pluck('id')->all())
            ->and($byType['closest_number']->option_order)->toBe([])
            ->and($byType['multiple_choice']->option_order)->toHaveCount(4);
    });
});

describe('translated in both languages', function () {
    it('needs no options for a closest-number question, only its text', function () {
        $both = Question::factory()->closestNumber()->create();
        $onlyEnglish = Question::factory()->closestNumber()->create(['text' => ['en' => 'How many?']]);

        expect(Question::translatedIn(['ar', 'en'])->pluck('id')->all())->toBe([$both->id])
            ->and($both->load('options')->isTranslatedIn(['ar', 'en']))->toBeTrue()
            ->and($onlyEnglish->load('options')->isTranslatedIn(['ar', 'en']))->toBeFalse();
    });

    it('still needs every option translated for a true/false question', function () {
        $ok = Question::factory()->trueFalse()->create();
        $broken = Question::factory()->trueFalse()->create();
        $broken->options()->first()->forgetTranslation('text', 'ar')->save();

        expect(Question::translatedIn(['ar', 'en'])->pluck('id')->all())->toBe([$ok->id])
            ->and($broken->load('options')->isTranslatedIn(['ar', 'en']))->toBeFalse();
    });

    it('still leaves out a multiple-choice question with no options', function () {
        Question::factory()->create();

        expect(Question::translatedIn(['ar', 'en'])->count())->toBe(0);
    });

    it('works inside a sub-select, as the admin filter uses it', function () {
        Question::factory()->closestNumber()->create();

        expect(Question::withTrashed()->whereNotIn('questions.id', Question::withTrashed()->translatedIn(['ar', 'en'])->select('questions.id'))->count())->toBe(0);
    });
});

describe('complete for its type', function () {
    it('accepts a good question of each type', function () {
        $category = Category::factory()->create();
        Question::factory()->for($category)->withOptions()->create();
        Question::factory()->for($category)->trueFalse()->create();
        Question::factory()->for($category)->closestNumber(10, 2)->create();

        expect(playableIn($category))->toBe(3);
    });

    it('leaves out a true/false question that is not exactly True and False with one right', function (string $what) {
        $category = Category::factory()->create();
        $question = Question::factory()->for($category)->trueFalse()->create();

        match ($what) {
            'three options' => QuestionOption::factory()->for($question)->create(['sort_order' => 3]),
            'one option' => $question->options()->latest('id')->first()->delete(),
            'no right answer' => $question->options()->update(['is_correct' => false]),
            'two right answers' => $question->options()->update(['is_correct' => true]),
        };

        expect(playableIn($category))->toBe(0)
            ->and($question->fresh()->load('options')->problems())->not->toBe([]);
    })->with(['three options', 'one option', 'no right answer', 'two right answers']);

    it('leaves out a closest-number question without a number, without a tolerance, or with options', function (string $what) {
        $category = Category::factory()->create();
        $question = Question::factory()->for($category)->closestNumber(100, 10)->create();

        match ($what) {
            'no number' => $question->update(['answer_number' => null]),
            'a tolerance of zero' => Question::query()->whereKey($question->id)->toBase()->update(['tolerance' => 0]),
            'no tolerance' => Question::query()->whereKey($question->id)->toBase()->update(['tolerance' => null]),
            'an option' => QuestionOption::factory()->for($question)->create(['is_correct' => true]),
        };

        expect(playableIn($category))->toBe(0);
    })->with(['no number', 'a tolerance of zero', 'no tolerance', 'an option']);

    it('does not look at a multiple-choice question any harder than before', function () {
        $category = categoryWithQuestions(3);

        expect(playableIn($category))->toBe(3);
    });

    it('lists what is wrong, in plain words, per type', function () {
        $trueFalse = Question::factory()->trueFalse()->create();
        $trueFalse->options()->latest('id')->first()->delete();
        $number = Question::factory()->closestNumber(5, 1)->create();
        $number->update(['answer_number' => null]);
        QuestionOption::factory()->for($number)->create();
        $choice = Question::factory()->withOptions(3)->create();

        expect($trueFalse->fresh()->load('options')->problems())->toContain('A true/false question needs exactly two options, True and False.')
            ->and($number->fresh()->load('options')->problems())->toContain('A closest-number question needs the correct number.', 'A closest-number question has no options.')
            ->and($choice->load('options')->problems())->toContain('A multiple-choice question needs 4 or 5 options.');
    });

    it('has no problems when it is complete, picture included', function () {
        Storage::disk(QuestionImage::DISK)->put('there.webp', webpBytes());
        $good = Question::factory()->closestNumber(5, 1)->withImage('there.webp')->create();
        $lost = Question::factory()->closestNumber(5, 1)->withImage('gone.webp')->create();

        expect($good->load('options')->problems())->toBe([])
            ->and($lost->load('options')->problems())->toBe(['The picture file is missing.']);
    });
});

describe('the pool counts every type', function () {
    it('draws true/false and closest-number questions like any other', function () {
        $category = Category::factory()->create();
        Question::factory()->for($category)->withOptions()->count(2)->create();
        Question::factory()->for($category)->trueFalse()->count(3)->create();
        Question::factory()->for($category)->closestNumber()->count(4)->create();
        Question::factory()->for($category)->closestNumber()->inactive()->create();

        $pool = app(QuestionPool::class);
        $settings = new RoomSettings(categoryIds: [$category->id], questionCount: 9);

        expect($pool->available($settings))->toBe(9)->and($pool->isEnough($settings))->toBeTrue()
            ->and($pool->isEnough(new RoomSettings(categoryIds: [$category->id], questionCount: 10)))->toBeFalse();
    });

    it('counts them toward the minimums the content report checks', function () {
        config(['lamma.content.category' => ['total' => 6, 'each' => 2]]);
        $category = Category::factory()->create(['sort_order' => 1]);
        foreach ([Difficulty::Easy, Difficulty::Medium, Difficulty::Hard] as $difficulty) {
            Question::factory()->for($category)->state(['difficulty' => $difficulty])->trueFalse()->create();
            Question::factory()->for($category)->state(['difficulty' => $difficulty])->closestNumber()->create();
        }

        $row = collect(app(QuestionReport::class)->rows())->firstWhere('category.id', $category->id);

        expect($row['total'])->toBe(6)->and($row['counts'])->toBe(['easy' => 2, 'medium' => 2, 'hard' => 2])->and($row['short'])->toBe([]);
    });

    it('can start a game made only of numbers, and one made of everything', function () {
        foreach ([['closest_number', 'closest_number', 'closest_number'], ['multiple_choice', 'true_false', 'closest_number']] as $types) {
            $room = typedRoom($types);

            $first = startGame($room);

            expect($first->position)->toBe(1)->and($room->fresh()->roomQuestions()->count())->toBe(3)
                ->and($first->question->type->value)->toBe($types[0]);
        }
    });
});

describe('a picture and a fun fact', function () {
    it('has a picture only when it has a path, and gives a root-relative address', function () {
        $with = Question::factory()->withImage('abc.webp')->create();
        $without = Question::factory()->create();

        expect($with->hasPicture())->toBeTrue()->and($with->imageUrl())->toStartWith('/storage/')->toEndWith('/abc.webp')
            ->and($without->hasPicture())->toBeFalse()->and($without->imageUrl())->toBeNull();
    });

    it('keeps a fun fact in each language, drops a blank one, and is fine without one', function () {
        $question = Question::factory()->create(['explanation' => ['ar' => 'معلومة', 'en' => '']]);
        $none = Question::factory()->create();

        expect($question->fresh()->getTranslations('explanation'))->toBe(['ar' => 'معلومة'])
            ->and($none->fresh()->getTranslations('explanation'))->toBe([]);
    });

    it('never needs the fun fact to be playable', function () {
        $category = Category::factory()->create();
        Question::factory()->for($category)->trueFalse()->create();

        expect(playableIn($category))->toBe(1);
    });
});

describe('the engine and the types it asks', function () {
    it('opens any type first', function (string $type) {
        $room = typedRoom([$type]);

        expect(startGame($room)->question->type->value)->toBe($type);
        expect(app(GameEngine::class)->progress($room->fresh(), $room->fresh()->currentQuestion()))->toBe(['answered' => 0, 'expected' => 3]);
    })->with(['multiple_choice', 'true_false', 'closest_number']);
});

describe('the migration', function () {
    it('rolls back and comes up again: the columns go and return, and a question keeps its text and becomes multiple choice', function () {
        $question = Question::factory()->withOptions()->create();
        $columns = fn () => [Schema::hasColumn('questions', 'type'), Schema::hasColumn('questions', 'answer_number'), Schema::hasColumn('questions', 'tolerance'), Schema::hasColumn('questions', 'explanation'),
            Schema::hasColumn('player_answers', 'number_value'), Schema::hasColumn('player_answers', 'closest_bonus')];

        expect($columns())->toBe([true, true, true, true, true, true]);

        Artisan::call('migrate:rollback', ['--step' => 1]);
        expect($columns())->toBe([false, false, false, false, false, false])->and(DB::table('questions')->count())->toBe(1);

        Artisan::call('migrate');
        expect($columns())->toBe([true, true, true, true, true, true])
            ->and($question->fresh()->type)->toBe(QuestionType::MultipleChoice)->and($question->fresh()->getTranslation('text', 'en'))->toBe($question->getTranslation('text', 'en'))
            ->and($question->fresh()->options)->toHaveCount(4);
    });
});

describe('a picture goes with its question', function () {
    it('is deleted when the question is deleted for good, and kept when it is only soft-deleted', function () {
        $path = app(QuestionImage::class)->store(jpegBytes(300, 200));
        $question = Question::factory()->withImage($path)->create();

        $question->delete();
        expect(Storage::disk(QuestionImage::DISK)->exists($path))->toBeTrue();

        $question->forceDelete();
        expect(Storage::disk(QuestionImage::DISK)->exists($path))->toBeFalse();
    });

    it('never deletes one of the shipped drawings, which other databases share by name', function () {
        $path = app(QuestionImage::class)->storeShipped('shape-hexagon.svg');
        $question = Question::factory()->withImage($path)->create();

        $question->forceDelete();

        expect(Storage::disk(QuestionImage::DISK)->exists($path))->toBeTrue();
    });
});
