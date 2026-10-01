<?php

use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Jobs\AdvanceQuestion;
use App\Jobs\RevealQuestion;
use App\Models\PlayerAnswer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000'));
});

/**
 * Play the queue like a worker: take the earliest job nobody has run, jump the clock to when it is due, run it. Repeat until
 * the queue is quiet. Returns how many jobs ran.
 */
function runQueueUntilQuiet(): int
{
    $ran = [];
    while (true) {
        $next = Queue::pushed(RevealQuestion::class)->merge(Queue::pushed(AdvanceQuestion::class))
            ->reject(fn ($job) => in_array(spl_object_id($job), $ran, true))
            ->sortBy(fn ($job) => $job->delay->getTimestampMs())
            ->first();
        if ($next === null) {
            return count($ran);
        }

        $ran[] = spl_object_id($next);
        if ($next->delay->isAfter(now())) {
            test()->travelTo($next->delay);
        }
        $next->handle(app(GameEngine::class));
    }
}

describe('a whole game driven by the clock and the queue', function () {
    it('plays from start to GameFinished with nobody touching anything', function () {
        $room = gameRoom(players: 3, questions: 3);
        $players = $room->players()->orderBy('id')->get();
        startGame($room);
        // Everyone answers question 1 right away, one player answers question 2, nobody answers question 3.
        foreach ($players as $player) {
            answer($player, $room->fresh()->currentQuestion(), $player->id !== $players[2]->id);
        }

        runQueueUntilQuiet();

        // (questions 2 and 3 were reached by the queue; answer question 2 by one player in between instead)
        expect($room->fresh()->status)->toBe(RoomStatus::Finished)
            ->and($room->roomQuestions()->whereNotNull('revealed_at')->count())->toBe(3)
            ->and($players[0]->fresh()->score)->toBe(100);
        Event::assertDispatchedTimes(GameFinished::class, 1);
    });

    it('keeps its own timeline: each question starts 5 seconds after the last was revealed, and is revealed after its time is up', function () {
        $room = gameRoom(players: 2, questions: 3, settings: ['secondsPerQuestion' => 10]);
        startGame($room);

        runQueueUntilQuiet();

        $questions = $room->roomQuestions()->get();
        foreach ($questions as $index => $question) {
            expect($question->ends_at->diffInMilliseconds($question->started_at, true))->toBe(10000.0)
                ->and($question->revealed_at->diffInMilliseconds($question->ends_at, true))->toBeGreaterThanOrEqual(500.0);
            if ($index > 0) {
                expect($question->started_at->diffInMilliseconds($questions[$index - 1]->revealed_at, true))->toBeGreaterThanOrEqual(5000.0);
            }
        }
        expect($room->fresh()->status)->toBe(RoomStatus::Finished);
    });

    it('moves on by itself when the host never touches anything and nobody answers', function () {
        $room = gameRoom(players: 2, questions: 3);
        startGame($room);

        $jobs = runQueueUntilQuiet();

        expect($jobs)->toBe(6)  // reveal + advance for each of the three questions
            ->and($room->fresh()->status)->toBe(RoomStatus::Finished)
            ->and($room->players()->sum('score'))->toBe(0);
        Event::assertDispatchedTimes(QuestionStarted::class, 3);
        Event::assertDispatchedTimes(QuestionRevealed::class, 3);
        Event::assertDispatchedTimes(GameFinished::class, 1);
    });
});

describe('scores over a full 10-question game', function () {
    it('add up: 100 per correct answer, nothing for wrong, unanswered or disconnected', function () {
        $room = gameRoom(players: 4, questions: 10);
        [$always, $evens, $never, $gone] = $room->players()->orderBy('id')->get()->all();
        $gone->update(['left_at' => now()]);   // dropped before the game: never waited for, never answers
        startGame($room);

        foreach (range(1, 10) as $position) {
            $question = $room->fresh()->currentQuestion();
            answer($always, $question, true);
            answer($evens, $question, $position % 2 === 0);
            answer($never, $question, false);                // all three connected players answered: early reveal
            app(GameEngine::class)->advance($room, $position, force: true);
        }

        expect($room->fresh()->status)->toBe(RoomStatus::Finished)
            ->and($always->fresh()->score)->toBe(1000)
            ->and($evens->fresh()->score)->toBe(500)
            ->and($never->fresh()->score)->toBe(0)
            ->and($gone->fresh()->score)->toBe(0)
            ->and(PlayerAnswer::count())->toBe(30)
            ->and(PlayerAnswer::where('is_correct', true)->count())->toBe(15)
            ->and(PlayerAnswer::sum('points'))->toBe(1500);
    });

    it('matches what every ScoreboardUpdated said, round by round, and the final ranking', function () {
        $room = gameRoom(players: 3, questions: 10);
        [$a, $b, $c] = $room->players()->orderBy('id')->get()->all();
        startGame($room);

        foreach (range(1, 10) as $position) {
            $question = $room->fresh()->currentQuestion();
            answer($a, $question, true);
            answer($b, $question, $position <= 5);
            answer($c, $question, $position % 3 === 0);
            app(GameEngine::class)->advance($room, $position, force: true);
        }

        $boards = Event::dispatched(ScoreboardUpdated::class)->map(fn ($call) => $call[0]->broadcastWith());
        expect($boards)->toHaveCount(10);

        $running = [$a->id => 0, $b->id => 0, $c->id => 0];
        foreach ($boards as $index => $board) {
            foreach ($board['ranking'] as $row) {
                $running[$row['player']['id']] += $row['gained'];

                expect($row['total'])->toBe($running[$row['player']['id']]);   // the board's total is the sum of what was gained so far
            }
            expect($board['position'])->toBe($index + 1);
        }

        $final = Event::dispatched(GameFinished::class)->first()[0]->broadcastWith()['ranking'];
        expect(collect($final)->pluck('total', 'player.id')->all())->toBe([$a->id => 1000, $b->id => 500, $c->id => 300])
            ->and(array_column($final, 'rank'))->toBe([1, 2, 3]);
    });

    it('keeps tied players on the same rank', function () {
        $room = gameRoom(players: 3, questions: 3);
        [$a, $b, $c] = $room->players()->orderBy('id')->get()->all();
        startGame($room);

        foreach ([1, 2, 3] as $position) {
            $question = $room->fresh()->currentQuestion();
            answer($a, $question, true);
            answer($b, $question, true);
            answer($c, $question, false);
            app(GameEngine::class)->advance($room, $position, force: true);
        }

        expect(array_column(Event::dispatched(GameFinished::class)->first()[0]->broadcastWith()['ranking'], 'rank'))->toBe([1, 1, 3]);
    });
});

describe('a full game by early reveals and the host override', function () {
    it('runs from start to GameFinished', function () {
        $room = gameRoom(players: 2, questions: 5);
        $players = $room->players()->orderBy('id')->get();

        expect($room->status)->toBe(RoomStatus::Lobby);
        startGame($room);
        expect($room->fresh()->status)->toBe(RoomStatus::Playing);

        foreach (range(1, 5) as $position) {
            $question = $room->fresh()->currentQuestion();
            expect($question->position)->toBe($position);
            foreach ($players as $player) {
                answer($player, $question, true);
            }
            expect($question->fresh()->revealed_at)->not->toBeNull();
            app(GameEngine::class)->advance($room, $position, force: true);
        }

        $room->refresh();
        expect($room->status)->toBe(RoomStatus::Finished)->and($room->finished_at)->not->toBeNull()->and($room->started_at)->not->toBeNull();
        Event::assertDispatchedTimes(GameStarted::class, 1);
        Event::assertDispatchedTimes(QuestionStarted::class, 5);
        Event::assertDispatchedTimes(QuestionRevealed::class, 5);
        Event::assertDispatchedTimes(ScoreboardUpdated::class, 5);
        Event::assertDispatchedTimes(PlayerAnswered::class, 10);
        Event::assertDispatchedTimes(GameFinished::class, 1);
        expect($players[0]->fresh()->score)->toBe(500);
    });

    it('asks every question exactly once, in position order', function () {
        $room = gameRoom(players: 1, questions: 5);
        $player = $room->players()->first();
        startGame($room);

        foreach (range(1, 5) as $position) {
            answer($player, $room->fresh()->currentQuestion());
            app(GameEngine::class)->advance($room, $position, force: true);
        }

        $shown = Event::dispatched(QuestionStarted::class)->map(fn ($call) => $call[0]->broadcastWith()['question']['position'])->all();
        expect($shown)->toBe([1, 2, 3, 4, 5])
            ->and($room->roomQuestions()->pluck('question_id')->unique())->toHaveCount(5);
    });
});
