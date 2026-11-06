<?php

use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Game\PlayerStats;
use App\Game\Points;
use App\Game\Scoreboard;
use App\Game\ScoringService;
use App\Models\PlayerAnswer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    at('12:00:00.000');
});

/** What a correct answer $ms after a question of $seconds opened scores. */
function scoreAt(int $ms, int $seconds = 20, bool $correct = true, bool $final = false): Points
{
    $start = CarbonImmutable::parse('2026-10-01 12:00:00.000');

    return (new ScoringService)->pointsFor($correct, $start->addMilliseconds($ms), $start, $start->addSeconds($seconds), $final);
}

describe('the speed bonus', function () {
    it('is full for the first second: reading the question and the network take a moment', function (int $ms) {
        expect(scoreAt($ms)->speedBonus)->toBe(100)->and(scoreAt($ms)->total)->toBe(200);
    })->with(['at 0 ms' => 0, 'at 999 ms' => 999, 'at 1000 ms' => 1000]);

    it('falls in a straight line from 1000 ms to the full duration, rounded to the nearest 5', function (int $ms, int $seconds, int $bonus) {
        expect(scoreAt($ms, $seconds)->speedBonus)->toBe($bonus);
    })->with([
        '20 s, just after the first second' => [1100, 20, 100],     // 99.5
        '20 s, a quarter of the way' => [5750, 20, 75],             // 75
        '20 s, half the duration' => [10000, 20, 55],               // 52.6
        '20 s, near the end' => [19000, 20, 5],                     // 5.3
        '20 s, the last moment' => [19999, 20, 0],                  // 0.005
        '15 s, half the duration' => [7500, 15, 55],                // 53.6
        '10 s, half the duration' => [5000, 10, 55],                // 55.6
        '30 s, half the duration' => [15000, 30, 50],               // 51.7
    ]);

    it('rounds an exact half upward, every time', function () {
        expect(scoreAt(10025)->speedBonus)->toBe(55); // exactly 52.5 on a 20 s question
    });

    it('is always a multiple of 5 between 0 and 100', function () {
        foreach (range(0, 20500, 37) as $ms) {
            $bonus = scoreAt($ms)->speedBonus;

            expect($bonus % 5)->toBe(0)->and($bonus)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
        }
    });

    it('is nothing at the full duration, and an answer in the grace period still scores the 100', function (int $ms) {
        expect(scoreAt($ms))->speedBonus->toBe(0)->base->toBe(100)->total->toBe(100);
    })->with(['at the deadline' => 20000, 'inside the grace' => 20001, 'the last moment of the grace' => 20500]);

    it('gives a wrong answer nothing, however fast', function () {
        expect(scoreAt(0, correct: false))->base->toBe(0)->speedBonus->toBe(0)->total->toBe(0);
    });
});

describe('the double final question', function () {
    it('doubles the base and the bonus', function () {
        expect(scoreAt(10000, final: true))->base->toBe(100)->speedBonus->toBe(55)->multiplier->toBe(2)->total->toBe(310);
    });

    it('still leaves a wrong answer on nothing', function () {
        expect(scoreAt(0, correct: false, final: true)->total)->toBe(0);
    });

    it('counts only the last question of a Classic game', function () {
        $room = gameRoom(players: 1, questions: 3);
        $player = $room->players()->first();
        startGame($room);

        foreach ([1, 2, 3] as $position) {
            answer($player, $room->fresh()->currentQuestion());
            app(GameEngine::class)->advance($room, $position, force: true);
        }

        expect(PlayerAnswer::query()->orderBy('id')->pluck('multiplier')->all())->toBe([1, 1, 2])
            ->and(PlayerAnswer::query()->orderBy('id')->pluck('points')->all())->toBe([200, 200, 400])
            ->and($player->fresh()->score)->toBe(800);
    });

    it('counts only the last question of a Vote game, not the last of a batch', function () {
        $room = voteRoom(players: 1, questions: 7, every: 3);
        $player = $room->players()->first();
        $engine = app(GameEngine::class);
        startGame($room);

        for ($guard = 0; $guard < 30 && $room->fresh()->status === RoomStatus::Playing; $guard++) {
            $question = $room->fresh()->currentQuestion();
            if ($question->revealed_at === null) {
                answer($player, $question);
            }
            if (($vote = votes()->openRound($room->fresh())) !== null) {
                $engine->closeVote($room, $vote->round, force: true);
            }
            $engine->advance($room, $question->position, force: true);
        }

        expect($room->fresh()->status)->toBe(RoomStatus::Finished)
            ->and(PlayerAnswer::query()->orderBy('id')->pluck('multiplier')->all())->toBe([1, 1, 1, 1, 1, 1, 2])
            ->and($player->fresh()->score)->toBe(6 * 200 + 400);
    });

    it('stays flat when the host turned it off', function () {
        $room = gameRoom(players: 1, questions: 1, settings: ['doubleFinal' => false]);
        $question = startGame($room);

        expect(answer($room->players()->first(), $question))->multiplier->toBe(1)->points->toBe(200);
    });
});

describe('what is stored', function () {
    it('keeps the base, the bonus and the multiplier on the answer; points is the total', function () {
        $room = gameRoom(players: 2, questions: 2);
        [$fast, $slow] = $room->players()->orderBy('id')->get()->all();
        $first = startGame($room);

        at('12:00:10.000');
        $answer = answer($fast, $first);

        expect($answer->fresh()->only('points', 'base_points', 'speed_bonus', 'multiplier'))
            ->toBe(['points' => 155, 'base_points' => 100, 'speed_bonus' => 55, 'multiplier' => 1])
            ->and(answer($slow, $first, false)->fresh()->only('points', 'base_points', 'speed_bonus'))
            ->toBe(['points' => 0, 'base_points' => 0, 'speed_bonus' => 0]);
    });

    it('decides the points when the answer is accepted and adds them at the reveal', function () {
        $room = gameRoom(players: 2, questions: 2);
        [$player] = $room->players()->orderBy('id')->get()->all();
        $question = startGame($room);

        at('12:00:10.000');
        answer($player, $question);
        expect($player->fresh()->score)->toBe(0);

        at('12:00:21.000'); // a late reveal does not change what the answer was worth
        app(GameEngine::class)->reveal($room, 1);

        expect($player->fresh()->score)->toBe(155);
    });

    it('adds an old answer\'s stored points untouched (no breakdown, no bonus)', function () {
        $room = gameRoom(players: 1, questions: 2);
        $player = $room->players()->first();
        $question = startGame($room);
        PlayerAnswer::create(['room_question_id' => $question->id, 'room_player_id' => $player->id, 'answered_at' => now(), 'is_correct' => true, 'points' => 100]);

        app(GameEngine::class)->reveal($room, 1, force: true);

        expect($player->fresh()->score)->toBe(100)->and($question->answers()->first()->speed_bonus)->toBeNull();
    });
});

describe('over a full game', function () {
    it('adds up on the scoreboard, and being quicker breaks what used to be a tie', function () {
        $room = gameRoom(players: 3, questions: 3);
        [$quick, $steady, $wrong] = $room->players()->orderBy('id')->get()->all();
        $engine = app(GameEngine::class);
        startGame($room);

        // Question 1: quick at once (200), steady at half time (155). Question 2: quick at once (200), steady at 15 s (125). The last: quick again (400), steady at 20 s (200).
        $totals = [];
        foreach ([1 => 10000, 2 => 15250, 3 => 20000] as $position => $steadyAt) {
            $question = $room->fresh()->currentQuestion();
            $opened = CarbonImmutable::instance($question->started_at);
            answer($quick, $question);
            answer($wrong, $question, false);
            $this->travelTo($opened->addMilliseconds($steadyAt));
            answer($steady, $question);
            $totals[$position] = collect(app(Scoreboard::class)->ranking($room->fresh(), $question->fresh()))->pluck('total', 'player.id')->all();
            $this->travelTo($opened->addSeconds(30));
            $engine->advance($room, $position, force: true);
        }

        $ranking = app(Scoreboard::class)->ranking($room->fresh());

        expect($room->fresh()->status)->toBe(RoomStatus::Finished)
            ->and($totals[1])->toBe([$quick->id => 200, $steady->id => 155, $wrong->id => 0])
            ->and($quick->fresh()->score)->toBe(800)
            ->and($steady->fresh()->score)->toBe(155 + 125 + 200)
            ->and($wrong->fresh()->score)->toBe(0)
            ->and(array_column($ranking, 'rank'))->toBe([1, 2, 3])
            ->and(array_column($ranking, 'total'))->toBe([800, 480, 0])
            ->and((int) PlayerAnswer::sum('points'))->toBe(1280);
    });

    it('keeps the correct-answer rate on correct answers, whatever they scored', function () {
        $user = User::factory()->create();
        [, $mine] = savedGame($user, score: 620, correct: 2, questions: 3);
        $mine->answers()->update(['points' => 310]);

        expect(app(PlayerStats::class)->totals($user))->correct->toBe(2)->questions->toBe(3)->rate->toBe(67);
    });
});
