<?php

use App\Enums\Difficulty;
use App\Enums\HostScreenLocale;
use App\Game\QuestionPool;
use App\Game\RoomSettings;
use App\Models\Category;
use App\Models\Question;
use App\Models\QuestionOption;

function pool(array $categoryIds, ?Difficulty $difficulty = null, int $count = 5): QuestionPool
{
    return new class($categoryIds, $difficulty, $count) extends QuestionPool
    {
        public RoomSettings $settings;

        public function __construct(array $ids, ?Difficulty $difficulty, int $count)
        {
            $this->settings = new RoomSettings(categoryIds: $ids, questionCount: $count, difficulty: $difficulty);
        }

        public function count(): int
        {
            return $this->available($this->settings);
        }

        public function enough(): bool
        {
            return $this->isEnough($this->settings);
        }
    };
}

it('counts active questions in the chosen categories only', function () {
    $mine = categoryWithQuestions(4);
    categoryWithQuestions(6); // another category

    expect(pool([$mine->id])->count())->toBe(4);
});

it('adds up several categories', function () {
    $a = categoryWithQuestions(3);
    $b = categoryWithQuestions(2);

    expect(pool([$a->id, $b->id])->count())->toBe(5);
});

it('filters by difficulty, and Mixed (null) takes every difficulty', function () {
    $category = categoryWithQuestions(2, Difficulty::Easy);
    Question::factory()->count(3)->withOptions()->create(['category_id' => $category->id, 'difficulty' => Difficulty::Hard]);

    expect(pool([$category->id], Difficulty::Easy)->count())->toBe(2)
        ->and(pool([$category->id], Difficulty::Hard)->count())->toBe(3)
        ->and(pool([$category->id], Difficulty::Medium)->count())->toBe(0)
        ->and(pool([$category->id], null)->count())->toBe(5);
});

it('skips inactive and soft-deleted questions', function () {
    $category = categoryWithQuestions(2);
    Question::factory()->inactive()->withOptions()->create(['category_id' => $category->id]);
    Question::factory()->withOptions()->create(['category_id' => $category->id])->delete();

    expect(pool([$category->id])->count())->toBe(2);
});

it('skips questions in an inactive category', function () {
    $category = categoryWithQuestions(3, category: ['is_active' => false]);

    expect(pool([$category->id])->count())->toBe(0);
});

describe('fully translated in both languages', function () {
    it('skips a question missing its Arabic or English text', function () {
        $category = categoryWithQuestions(1);
        Question::factory()->withOptions()->create(['category_id' => $category->id, 'text' => ['en' => 'Only English?']]);
        Question::factory()->withOptions()->create(['category_id' => $category->id, 'text' => ['ar' => 'عربي فقط؟']]);

        expect(pool([$category->id])->count())->toBe(1);
    });

    it('skips a question when any option lacks a translation', function () {
        $category = categoryWithQuestions(1);
        $broken = Question::factory()->withOptions()->create(['category_id' => $category->id]);
        QuestionOption::factory()->for($broken)->create(['text' => ['en' => 'No Arabic']]);

        expect(pool([$category->id])->count())->toBe(1);
    });

    it('skips a question with no options at all', function () {
        $category = categoryWithQuestions(1);
        Question::factory()->create(['category_id' => $category->id]);

        expect(pool([$category->id])->count())->toBe(1);
    });

    it('requires both languages even when the host screen uses one', function () {
        $category = categoryWithQuestions(1);
        Question::factory()->withOptions()->create(['category_id' => $category->id, 'text' => ['en' => 'English only?']]);
        $settings = new RoomSettings(categoryIds: [$category->id], hostScreenLocale: HostScreenLocale::En);

        expect(app(QuestionPool::class)->available($settings))->toBe(1);
    });
});

it('says whether there are enough questions for the game length', function () {
    $category = categoryWithQuestions(5);

    expect(pool([$category->id], count: 5)->enough())->toBeTrue()
        ->and(pool([$category->id], count: 10)->enough())->toBeFalse();
});

it('finds nothing when no category is chosen or the ids are unknown', function () {
    categoryWithQuestions(5);

    expect(pool([])->count())->toBe(0)->and(pool([Category::max('id') + 99])->count())->toBe(0);
});
