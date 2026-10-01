<?php

use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\AnswerRejected;
use App\Game\GameEngine;
use App\Game\ScoringService;
use App\Models\PlayerAnswer;
use App\Models\QuestionOption;
use App\Models\RoomQuestion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000'));
});

/** The reason an answer was refused. */
function rejection(callable $submit): string
{
    try {
        $submit();
    } catch (AnswerRejected $e) {
        return $e->reason;
    }

    test()->fail('Expected the answer to be rejected');
}

describe('accepting an answer', function () {
    it('records the option, the time, whether it is right and the points', function () {
        [$room, $question, $players] = openQuestion();
        at('12:00:05.250');

        $answer = answer($players[0], $question, true);

        expect($answer->room_question_id)->toBe($question->id)
            ->and($answer->room_player_id)->toBe($players[0]->id)
            ->and($answer->question_option_id)->toBe(optionOf($question, true))
            ->and($answer->is_correct)->toBeTrue()
            ->and($answer->points)->toBe(100)
            ->and($answer->answered_at->format('H:i:s.v'))->toBe('12:00:05.250');
    });

    it('gives a wrong answer 0 points', function () {
        [, $question, $players] = openQuestion();

        $answer = answer($players[0], $question, false);

        expect($answer->is_correct)->toBeFalse()->and($answer->points)->toBe(0);
    });

    it('leaves the score alone until the reveal', function () {
        [, $question, $players] = openQuestion();

        answer($players[0], $question, true);

        expect($players[0]->fresh()->score)->toBe(0);
    });

    it('uses the ScoringService: flat 100 for a correct answer, nothing for a wrong one', function () {
        $scoring = new ScoringService;

        expect($scoring->pointsFor(true))->toBe(100)->and($scoring->pointsFor(false))->toBe(0)
            ->and(ScoringService::POINTS_PER_CORRECT_ANSWER)->toBe(100);
    });

    it('asks the ScoringService for the points, so a speed bonus only has to change that class', function () {
        [, $question, $players] = openQuestion();
        $this->app->instance(ScoringService::class, new class extends ScoringService
        {
            public function pointsFor(bool $correct, ?CarbonInterface $answeredAt = null, ?RoomQuestion $question = null): int
            {
                return $correct ? 150 : 5;
            }
        });

        expect(answer($players[0], $question, true)->points)->toBe(150)
            ->and(answer($players[1], $question, false)->points)->toBe(5);
    });

    it('broadcasts PlayerAnswered with who and how many, never what they chose', function () {
        [$room, $question, $players] = openQuestion();

        answer($players[0], $question, true);

        Event::assertDispatched(PlayerAnswered::class, function (PlayerAnswered $event) use ($room, $players) {
            $payload = $event->broadcastWith();

            return $event->roomCode === $room->code
                && $payload['player'] === ['id' => $players[0]->id, 'nickname' => $players[0]->nickname]
                && $payload['answered'] === 1 && $payload['expected'] === 3 && $payload['position'] === 1
                && ! str_contains(json_encode($payload), 'option');
        });
    });

    it('counts the answers so far on each broadcast', function () {
        [, $question, $players] = openQuestion();

        answer($players[0], $question);
        answer($players[1], $question);

        $counts = Event::dispatched(PlayerAnswered::class)->map(fn ($call) => $call[0]->broadcastWith()['answered'])->all();
        expect($counts)->toBe([1, 2]);
    });
});

describe('time: the deadline and the grace period', function () {
    it('accepts an answer right up to ends_at plus 500 ms', function (string $time) {
        [, $question, $players] = openQuestion();
        at($time);

        expect(answer($players[0], $question)->exists)->toBeTrue();
    })->with(['at the start' => '12:00:00.000', 'just before the end' => '12:00:19.999', 'at the end' => '12:00:20.000', 'inside the grace' => '12:00:20.400', 'last moment of grace' => '12:00:20.500']);

    it('rejects an answer after the grace period', function (string $time) {
        [, $question, $players] = openQuestion();
        at($time);

        expect(rejection(fn () => answer($players[0], $question)))->toBe(AnswerRejected::LATE);
        expect(PlayerAnswer::count())->toBe(0);
    })->with(['a millisecond late' => '12:00:20.501', 'a second late' => '12:00:21.500', 'long after' => '12:05:00.000']);

    it('trusts the server clock, never the client: there is no client time to send', function () {
        $parameters = (new ReflectionMethod(GameEngine::class, 'submitAnswer'))->getParameters();

        expect(array_map(fn ($p) => $p->getName(), $parameters))->toBe(['player', 'optionId']);
    });

    it('does not reveal on time alone before the grace period is over', function () {
        [$room, $question] = openQuestion();
        at('12:00:20.400');

        expect(app(GameEngine::class)->reveal($room, 1)->value)->toBe('too_early');
        expect($question->fresh()->revealed_at)->toBeNull();

        at('12:00:20.500');
        expect(app(GameEngine::class)->reveal($room, 1)->value)->toBe('done');
    });
});

describe('one answer per player', function () {
    it('rejects a second answer, even with a different option, and keeps the first', function () {
        [, $question, $players] = openQuestion();
        $first = answer($players[0], $question, false);

        expect(rejection(fn () => answer($players[0], $question, true)))->toBe(AnswerRejected::ALREADY_ANSWERED);
        expect(rejection(fn () => answer($players[0], $question, false)))->toBe(AnswerRejected::ALREADY_ANSWERED);

        expect(PlayerAnswer::count())->toBe(1)->and($first->fresh()->is_correct)->toBeFalse();
        Event::assertDispatchedTimes(PlayerAnswered::class, 1);
    });

    it('lets different players answer the same question', function () {
        [, $question, $players] = openQuestion();

        foreach ($players as $player) {
            answer($player, $question);
        }

        expect(PlayerAnswer::count())->toBe(3);
    });

    it('relies on the unique index when two requests race past the check', function () {
        [, $question, $players] = openQuestion();
        PlayerAnswer::creating(function (PlayerAnswer $answer) {
            DB::table('player_answers')->insert([
                'room_question_id' => $answer->room_question_id, 'room_player_id' => $answer->room_player_id,
                'question_option_id' => $answer->question_option_id, 'answered_at' => now()->format('Y-m-d H:i:s.v'),
                'is_correct' => 0, 'points' => 0, 'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
            ]);
        });

        // (The racing insert happens inside this test's transaction, so it rolls back with the rejection; in production it is another connection's.)
        expect(rejection(fn () => answer($players[0], $question)))->toBe(AnswerRejected::ALREADY_ANSWERED);
    });

    it('has a unique index on question and player', function () {
        [, $question, $players] = openQuestion();
        PlayerAnswer::factory()->create(['room_question_id' => $question->id, 'room_player_id' => $players[0]->id]);

        expect(fn () => PlayerAnswer::factory()->create(['room_question_id' => $question->id, 'room_player_id' => $players[0]->id]))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('what can be answered, and when', function () {
    it('rejects an option that does not belong to this question', function () {
        [, $question, $players] = openQuestion();
        $stranger = QuestionOption::factory()->create();

        expect(rejection(fn () => app(GameEngine::class)->submitAnswer($players[0], $stranger->id)))->toBe(AnswerRejected::INVALID_OPTION);
        expect(rejection(fn () => app(GameEngine::class)->submitAnswer($players[0], 999999)))->toBe(AnswerRejected::INVALID_OPTION);
        expect(PlayerAnswer::count())->toBe(0);
    });

    it('rejects an option of another question in the same game', function () {
        [$room, $question, $players] = openQuestion();
        $later = $room->roomQuestions()->where('position', 2)->first();

        expect(rejection(fn () => answer($players[0], $later)))->toBe(AnswerRejected::INVALID_OPTION);
    });

    it('rejects answers in a lobby', function () {
        $room = gameRoom(players: 1);
        $player = $room->players()->first();

        expect(rejection(fn () => app(GameEngine::class)->submitAnswer($player, 1)))->toBe(AnswerRejected::CLOSED);
    });

    it('rejects answers once the question is revealed', function () {
        [$room, $question, $players] = openQuestion();
        at('12:00:21.000');
        app(GameEngine::class)->reveal($room, 1);

        expect(rejection(fn () => answer($players[0], $question)))->toBe(AnswerRejected::CLOSED);
    });

    it('rejects answers once the game is finished', function () {
        [$room, $question, $players] = openQuestion();
        $room->update(['status' => RoomStatus::Finished]);

        expect(rejection(fn () => answer($players[0], $question)))->toBe(AnswerRejected::CLOSED);
    });

    it('accepts an answer from a player who is marked disconnected but still has time', function () {
        [, $question, $players] = openQuestion();
        $players[0]->update(['left_at' => now()->subSeconds(3)]); // reconnecting: the host has not seen them join yet

        expect(answer($players[0], $question)->exists)->toBeTrue();
    });
});
