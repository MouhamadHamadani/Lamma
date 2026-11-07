<?php

use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Game\Podium;
use App\Game\Scoreboard;
use App\Game\Transition;
use App\Models\Category;
use App\Models\PlayerAnswer;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomQuestion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000'));
});

/** What a right answer is worth to a player who answers at once, as the rules say: 100 and 100 speed for a tap; for a number 100 (the exact guess) and 50 for the closest. */
function worth(QuestionType $type): int
{
    return $type === QuestionType::ClosestNumber ? 150 : 200;
}

describe('a whole Classic game of every type', function () {
    it('plays through, scores add up by the rules of each type, and the last question counts double for all of them', function () {
        // 9 questions: 3 multiple choice, 3 true/false, 3 closest number (1969, tolerance 10).
        $room = typedRoom(array_merge(...array_fill(0, 3, ['multiple_choice', 'true_false', 'closest_number'])), players: 3, settings: ['secondsPerQuestion' => 20]);
        $engine = app(GameEngine::class);
        [$always, $evens, $never] = $room->players()->orderBy('id')->get()->all();
        startGame($room);

        $expected = [$always->id => 0, $evens->id => 0, $never->id => 0];
        foreach (range(1, 9) as $position) {
            $roomQuestion = RoomQuestion::query()->where('room_id', $room->id)->where('position', $position)->first();
            $type = $roomQuestion->question->type;
            $multiplier = $position === 9 ? 2 : 1;

            answer($always, $roomQuestion, true);
            answer($evens, $roomQuestion, $position % 2 === 0);
            answer($never, $roomQuestion, false);   // all three answered: revealed at once
            expect($roomQuestion->fresh()->revealed_at)->not->toBeNull();

            // A number guessed exactly by everyone who is right: they tie as the closest, and all get the extra.
            $expected[$always->id] += worth($type) * $multiplier;
            $expected[$evens->id] += $position % 2 === 0 ? worth($type) * $multiplier : 0;
            $engine->advance($room, $position, force: true);
        }

        $scores = $room->players()->pluck('score', 'id')->all();
        expect($room->fresh()->status)->toBe(RoomStatus::Finished)
            ->and($scores)->toEqual($expected)
            ->and(PlayerAnswer::count())->toBe(27)
            ->and(PlayerAnswer::sum('points'))->toBe(array_sum($expected))
            ->and(PlayerAnswer::query()->whereHas('roomQuestion', fn ($q) => $q->where('position', 9))->pluck('multiplier')->unique()->all())->toBe([2])
            ->and(PlayerAnswer::query()->whereHas('roomQuestion', fn ($q) => $q->where('position', '<', 9))->pluck('multiplier')->unique()->all())->toBe([1]);
        Event::assertDispatchedTimes(QuestionRevealed::class, 9);
        Event::assertDispatchedTimes(GameFinished::class, 1);
    });

    it('ranks the players and builds the results from the answers whatever their type', function () {
        $room = typedRoom(['multiple_choice', 'true_false', 'closest_number'], players: 3, settings: ['doubleFinal' => false]);
        $engine = app(GameEngine::class);
        [$ana, $ben, $cy] = $room->players()->orderBy('id')->get()->all();
        startGame($room);

        foreach ([1, 2, 3] as $position) {
            $roomQuestion = RoomQuestion::query()->where('room_id', $room->id)->where('position', $position)->first();
            answer($ana, $roomQuestion, true);
            answer($ben, $roomQuestion, $position !== 2);
            answer($cy, $roomQuestion, false);
            $engine->advance($room, $position, force: true);
        }

        $ranking = app(Scoreboard::class)->ranking($room->fresh());
        $winners = app(Podium::class)->winners($ranking);

        expect(array_column(array_column($ranking, 'player'), 'id'))->toBe([$ana->id, $ben->id, $cy->id])
            ->and(array_column($ranking, 'rank'))->toBe([1, 2, 3])
            ->and($ranking[0]['total'])->toBe(200 + 200 + 150)->and($ranking[1]['total'])->toBe(200 + 0 + 150)->and($ranking[2]['total'])->toBe(0)
            ->and($winners)->toHaveCount(1)->and($winners[0]['id'])->toBe($ana->id);
        expect(Room::completed()->whereKey($room->id)->exists())->toBeTrue();
    });
});

/** A top-level category with exactly one question of each type, easy, so a Vote batch of three draws all of them. */
function categoryOfEveryType(): Category
{
    $category = Category::factory()->create();
    Question::factory()->for($category)->state(['difficulty' => Difficulty::Easy])->withOptions()->create();
    Question::factory()->for($category)->state(['difficulty' => Difficulty::Easy])->trueFalse()->create();
    Question::factory()->for($category)->state(['difficulty' => Difficulty::Easy])->closestNumber(1969, 10)->create();

    return $category;
}

describe('a whole Vote game of every type', function () {
    it('plays three batches, one vote and one automatic pick between them, and every type is asked, answered and scored in each batch', function () {
        $categories = [categoryOfEveryType(), categoryOfEveryType(), categoryOfEveryType()];
        $room = voteRoom(players: 3, questions: 9, every: 3, categories: $categories, settings: ['secondsPerQuestion' => 20]);
        $engine = app(GameEngine::class);
        startGame($room);
        $players = $room->players()->orderBy('id')->get();

        $votesCast = 0;
        for ($guard = 0; $guard < 60 && $room->fresh()->status === RoomStatus::Playing; $guard++) {
            $roomQuestion = $room->fresh()->currentQuestion();
            if ($roomQuestion->revealed_at === null) {
                foreach ($players as $index => $player) {
                    answer($player, $roomQuestion, $index === 0 || $roomQuestion->position % 2 === 0);
                }
                expect($roomQuestion->fresh()->revealed_at)->not->toBeNull();   // everyone answered: revealed at once, whatever the type
            }

            if (($vote = votes()->openRound($room->fresh())) !== null) {
                foreach ($players as $player) {
                    $engine->castVote($player, $vote->option_ids[0]);
                    $votesCast++;
                }
            }
            $engine->advance($room, $roomQuestion->position, force: true);
        }

        $room = $room->fresh();
        $asked = $room->roomQuestions()->with('question')->get();
        $byBatch = $asked->chunk(3);

        expect($room->status)->toBe(RoomStatus::Finished)->and($asked)->toHaveCount(9)->and($votesCast)->toBe(3)   // one vote, three voters: the last category left needs no vote
            ->and($asked->map(fn (RoomQuestion $q) => $q->question->type->value)->countBy()->sortKeys()->all())->toBe(['closest_number' => 3, 'multiple_choice' => 3, 'true_false' => 3])
            ->and($asked->every(fn (RoomQuestion $q) => $q->revealed_at !== null))->toBeTrue()
            ->and(PlayerAnswer::count())->toBe(27);
        // Each batch of three is one category, and every batch has one of each type.
        expect($byBatch->map(fn (Collection $batch) => $batch->map(fn (RoomQuestion $q) => topCategoryOf($q))->unique()->count())->all())->toBe([1, 1, 1])
            ->and($byBatch->map(fn (Collection $batch) => $batch->map(fn (RoomQuestion $q) => $q->question->type->value)->unique()->count())->all())->toBe([3, 3, 3]);
        // The last question counts double in a Vote game too, for every type; and the scores are the answers' points.
        expect(PlayerAnswer::query()->whereHas('roomQuestion', fn ($q) => $q->where('position', 9))->pluck('multiplier')->unique()->all())->toBe([2])
            ->and($room->players()->sum('score'))->toBe((int) PlayerAnswer::sum('points'));
        expect(Room::completed()->whereKey($room->id)->exists())->toBeTrue()->and($room->playedCategories())->toHaveCount(3);
    });

    it('opens a vote after a number question just like after any other', function () {
        $categories = [categoryOfEveryType(), categoryOfEveryType(), categoryOfEveryType()];
        $room = voteRoom(players: 2, questions: 9, every: 3, categories: $categories);
        $engine = app(GameEngine::class);
        startGame($room);

        // Play batch 1 until its last question, then make sure that one is a number and a vote follows.
        foreach ([1, 2, 3] as $position) {
            $roomQuestion = $room->fresh()->currentQuestion();
            $room->players()->orderBy('id')->get()->each(fn ($player) => answer($player, $roomQuestion, true));
            $transition = $engine->advance($room, $position, force: true);
        }

        expect($transition)->toBe(Transition::Voting)->and(votes()->openRound($room->fresh()))->not->toBeNull();
    });
});
