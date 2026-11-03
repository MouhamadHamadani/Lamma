<?php

use App\Enums\Difficulty;
use App\Game\CategoryVote;
use App\Game\GameEngine;
use App\Game\GameStartException;
use App\Models\Category;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomQuestion;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake();
    Queue::fake();
});

function votes(): CategoryVote
{
    return app(CategoryVote::class);
}

describe('the first batch', function () {
    it('draws only the first batch, from the first category, with the option order fixed', function () {
        $room = voteRoom(questions: 20, every: 5);
        startGame($room);

        $questions = $room->roomQuestions()->get();
        expect($questions->pluck('position')->all())->toBe([1, 2, 3, 4, 5])
            ->and($questions->map(fn (RoomQuestion $question) => topCategoryOf($question))->unique()->all())->toBe([$room->first_category_id])
            ->and($questions->every(fn (RoomQuestion $question) => count($question->option_order) === 4))->toBeTrue()
            ->and($room->fresh()->totalQuestions())->toBe(20);
    });

    it('keeps drawing everything at the start in a Classic game', function () {
        $room = gameRoom(questions: 6);
        startGame($room);

        expect($room->roomQuestions()->count())->toBe(6)->and($room->fresh()->totalQuestions())->toBe(6);
    });

    it('refuses to start when the first category can no longer fill its batch', function () {
        $first = categoryWithQuestions(2);
        $room = voteRoom(questions: 10, every: 5, categories: [$first, categoryWithQuestions(1), categoryWithQuestions(1)]);

        expect(fn () => app(GameEngine::class)->start($room, $room->host))->toThrow(GameStartException::class)
            ->and($room->roomQuestions()->count())->toBe(0);
    });
});

describe('the next batch', function () {
    it('appends the winning category\'s batch with the following positions and never repeats a question', function () {
        $room = voteRoom(questions: 20, every: 5);
        startGame($room);
        $winner = $room->pool()->pluck('categories.id')->last();

        $added = votes()->drawBatch($room, $winner, 5);

        $batch = $room->roomQuestions()->where('position', '>', 5)->get();
        expect($added)->toBe(5)
            ->and($batch->pluck('position')->all())->toBe([6, 7, 8, 9, 10])
            ->and($batch->map(fn ($question) => topCategoryOf($question))->unique()->all())->toBe([$winner])
            ->and($room->roomQuestions()->pluck('question_id')->duplicates())->toBeEmpty();
    });

    it('draws a top-level winner from all of its subcategories', function () {
        $parent = Category::factory()->create();
        $football = categoryWithQuestions(3, category: ['parent_id' => $parent->id]);
        $tennis = categoryWithQuestions(3, category: ['parent_id' => $parent->id]);
        $room = voteRoom(questions: 10, every: 5, categories: [categoryWithQuestions(6), $parent, categoryWithQuestions(6)]);
        startGame($room);

        votes()->drawBatch($room, $parent->id, 5);

        $categories = $room->roomQuestions()->where('position', '>', 5)->with('question')->get()->pluck('question.category_id')->unique();
        expect($categories->diff([$football->id, $tennis->id]))->toBeEmpty()
            ->and($categories)->toHaveCount(2);
    });

    it('never draws a question that is not translated in both languages, or of another difficulty', function () {
        $winner = Category::factory()->create();
        Question::factory()->count(5)->withOptions()->create(['category_id' => $winner->id, 'difficulty' => Difficulty::Hard]);
        Question::factory()->count(5)->withOptions()->create(['category_id' => $winner->id, 'difficulty' => Difficulty::Easy]);
        Question::factory()->count(5)->withOptions()->create(['category_id' => $winner->id, 'difficulty' => Difficulty::Hard, 'text' => ['en' => 'English only']]);
        $first = categoryWithQuestions(5, Difficulty::Hard);
        $room = voteRoom(questions: 10, every: 5, categories: [$first, $winner, categoryWithQuestions(5, Difficulty::Hard)], settings: ['difficulty' => Difficulty::Hard]);
        startGame($room);

        votes()->drawBatch($room, $winner->id, 5);

        $drawn = $room->roomQuestions()->where('position', '>', 5)->with('question')->get()->pluck('question');
        expect($drawn)->toHaveCount(5)
            ->and($drawn->every(fn (Question $question) => $question->difficulty === Difficulty::Hard && $question->getTranslation('text', 'ar', false) !== ''))->toBeTrue();
    });

    it('avoids the questions of the host\'s last rooms when there are enough others', function () {
        $winner = categoryWithQuestions(10);
        $asked = $winner->questions()->take(5)->pluck('id');
        $earlier = Room::factory()->finished()->create();
        $asked->values()->each(fn ($id, $i) => RoomQuestion::factory()->create(['room_id' => $earlier->id, 'question_id' => $id, 'position' => $i + 1]));
        $room = voteRoom(questions: 10, every: 5, categories: [categoryWithQuestions(5), $winner, categoryWithQuestions(5)], host: $earlier->host);
        startGame($room);

        votes()->drawBatch($room, $winner->id, 5);

        expect($room->roomQuestions()->where('position', '>', 5)->pluck('question_id')->intersect($asked))->toBeEmpty();
    });

    it('fills a short batch from the other pool categories and logs it, so the game still reaches its total', function () {
        Log::spy();
        $thin = categoryWithQuestions(2);
        $room = voteRoom(questions: 10, every: 5, categories: [categoryWithQuestions(5), $thin, categoryWithQuestions(8)]);
        startGame($room);

        $added = votes()->drawBatch($room, $thin->id, 5);

        expect($added)->toBe(5)
            ->and($room->roomQuestions()->count())->toBe(10)
            ->and($room->roomQuestions()->pluck('question_id')->duplicates())->toBeEmpty();
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['category_id'] === $thin->id && $context['found'] === 2);
    });
});

describe('batch sizes', function () {
    it('makes the last batch shorter when the total is not a multiple: 20 every 7 is 7, 7, 6', function () {
        $room = voteRoom(questions: 20, every: 7, perCategory: 10);

        expect(votes()->batchSize($room, 0))->toBe(7)
            ->and(votes()->batchSize($room, 7))->toBe(7)
            ->and(votes()->batchSize($room, 14))->toBe(6)
            ->and(collect(range(1, 20))->filter(fn (int $n) => votes()->due($room, $n))->values()->all())->toBe([7, 14])
            ->and(CategoryVote::voteCount(20, 7))->toBe(2);
    });

    it('votes before questions 6, 11 and 16 in a game of 20 every 5, never after the last batch', function () {
        $room = voteRoom(questions: 20, every: 5);

        expect(collect(range(1, 20))->filter(fn (int $n) => votes()->due($room, $n))->values()->all())->toBe([5, 10, 15])
            ->and(CategoryVote::voteCount(20, 5))->toBe(3)
            ->and(CategoryVote::voteCount(10, 10))->toBe(0);
    });

    it('never votes in a Classic game', function () {
        $room = gameRoom(questions: 10);

        expect(collect(range(1, 10))->filter(fn (int $n) => votes()->due($room, $n)))->toBeEmpty();
    });
});
