<?php

use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Game\Transition;
use App\Jobs\AdvanceQuestion;
use App\Jobs\RevealQuestion;
use App\Models\RoomPlayer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000'));
});

function engine(): GameEngine
{
    return app(GameEngine::class);
}

/** Run a queued job the way a worker would, then forget it. */
function work(object $job): void
{
    $job->handle(app(GameEngine::class));
}

describe('starting a question', function () {
    it('opens question 1 for the room\'s seconds_per_question and tells everyone', function () {
        $room = gameRoom(players: 2, questions: 3, settings: ['secondsPerQuestion' => 30]);

        $question = startGame($room);

        expect($question->position)->toBe(1)
            ->and($question->started_at->format('H:i:s.v'))->toBe('12:00:00.000')
            ->and($question->ends_at->format('H:i:s.v'))->toBe('12:00:30.000')
            ->and($question->revealed_at)->toBeNull()
            ->and($room->roomQuestions()->whereNotNull('started_at')->count())->toBe(1);
        Event::assertDispatched(QuestionStarted::class, fn (QuestionStarted $e) => $e->roomCode === $room->code && $e->broadcastWith()['question']['position'] === 1);
    });

    it('schedules the reveal for ends_at plus the answer grace', function () {
        $room = gameRoom();

        startGame($room);

        Queue::assertPushed(RevealQuestion::class, fn (RevealQuestion $job) => $job->roomId === $room->id && $job->position === 1
            && $job->delay->format('H:i:s.v') === '12:00:20.500');
        Queue::assertPushed(RevealQuestion::class, 1);
    });
});

describe('revealing', function () {
    it('shows the answer: sets revealed_at, adds the points, broadcasts and schedules the next question', function () {
        [$room, $question, $players] = openQuestion();
        answer($players[0], $question, true);
        answer($players[1], $question, false);
        at('12:00:21.000');

        $result = engine()->reveal($room, 1);

        expect($result)->toBe(Transition::Done)
            ->and($question->fresh()->revealed_at->format('H:i:s.v'))->toBe('12:00:21.000')
            ->and($players[0]->fresh()->score)->toBe(100)
            ->and($players[1]->fresh()->score)->toBe(0)
            ->and($players[2]->fresh()->score)->toBe(0);
        Event::assertDispatchedTimes(QuestionRevealed::class, 1);
        Event::assertDispatchedTimes(ScoreboardUpdated::class, 1);
        Queue::assertPushed(AdvanceQuestion::class, fn (AdvanceQuestion $job) => $job->roomId === $room->id && $job->position === 1
            && $job->delay->format('H:i:s.v') === '12:00:26.000');
    });

    it('reveals a question nobody answered, once time is up', function () {
        [$room, $question] = openQuestion();
        at('12:00:20.500');

        expect(engine()->reveal($room, 1))->toBe(Transition::Done)->and($question->fresh()->revealed_at)->not->toBeNull();
    });

    it('is refused while time is left and someone has not answered', function () {
        [$room, $question, $players] = openQuestion();
        answer($players[0], $question);
        at('12:00:10.000');

        expect(engine()->reveal($room, 1))->toBe(Transition::TooEarly)->and($question->fresh()->revealed_at)->toBeNull();
        Event::assertNotDispatched(QuestionRevealed::class);
    });

    it('only reveals the current question', function () {
        [$room] = openQuestion();
        at('12:01:00.000');

        expect(engine()->reveal($room, 2))->toBe(Transition::Stale)->and(engine()->reveal($room, 7))->toBe(Transition::Stale);
    });

    it('does nothing in a lobby or a finished game', function () {
        $lobby = gameRoom();
        expect(engine()->reveal($lobby, 1))->toBe(Transition::Stale);

        [$room] = openQuestion();
        $room->update(['status' => RoomStatus::Finished]);
        at('12:01:00.000');
        expect(engine()->reveal($room, 1))->toBe(Transition::Stale);
    });
});

describe('revealing early', function () {
    it('happens as soon as every connected player has answered', function () {
        [$room, $question, $players] = openQuestion();

        answer($players[0], $question);
        answer($players[1], $question);
        expect($question->fresh()->revealed_at)->toBeNull();
        at('12:00:04.000');
        answer($players[2], $question, false);

        expect($question->fresh()->revealed_at->format('H:i:s'))->toBe('12:00:04')
            ->and($players[0]->fresh()->score)->toBe(100);
        Event::assertDispatchedTimes(QuestionRevealed::class, 1);
        Queue::assertPushed(AdvanceQuestion::class, 1);
    });

    it('does not wait for disconnected players', function () {
        [$room, $question, $players] = openQuestion();
        $players[2]->update(['left_at' => now()->subSeconds(10)]);

        answer($players[0], $question);
        expect($question->fresh()->revealed_at)->toBeNull();
        answer($players[1], $question);

        expect($question->fresh()->revealed_at)->not->toBeNull();
    });

    it('does not reveal early with nobody connected', function () {
        [$room, $question, $players] = openQuestion();
        RoomPlayer::query()->update(['left_at' => now()->subSeconds(10)]);

        answer($players[0], $question);

        expect($question->fresh()->revealed_at)->toBeNull();
    });

    it('still counts a player who answered and then dropped, as answered', function () {
        [$room, $question, $players] = openQuestion();
        answer($players[0], $question);
        $players[0]->update(['left_at' => now()]);
        $players[2]->update(['left_at' => now()]);

        answer($players[1], $question);

        expect($question->fresh()->revealed_at)->not->toBeNull();
    });

    it('waits again for a player who reconnects before the others finish', function () {
        [$room, $question, $players] = openQuestion();
        $players[2]->update(['left_at' => now()]);
        answer($players[0], $question);
        $players[2]->update(['left_at' => null]); // back

        answer($players[1], $question);

        expect($question->fresh()->revealed_at)->toBeNull(); // the returning player can still answer
        answer($players[2], $question);
        expect($question->fresh()->revealed_at)->not->toBeNull();
    });
});

describe('reveal is idempotent', function () {
    it('does nothing the second time: no second broadcast, no double points, no second advance', function () {
        [$room, $question, $players] = openQuestion();
        answer($players[0], $question, true);
        at('12:00:21.000');

        $first = engine()->reveal($room, 1);
        $second = engine()->reveal($room, 1);
        $third = engine()->reveal($room, 1);

        expect([$first, $second, $third])->toBe([Transition::Done, Transition::Already, Transition::Already])
            ->and($players[0]->fresh()->score)->toBe(100);
        Event::assertDispatchedTimes(QuestionRevealed::class, 1);
        Event::assertDispatchedTimes(ScoreboardUpdated::class, 1);
        Queue::assertPushed(AdvanceQuestion::class, 1);
    });

    it('survives the reveal job running twice', function () {
        [$room, $question, $players] = openQuestion();
        answer($players[0], $question, true);
        at('12:00:21.000');

        work(new RevealQuestion($room->id, 1));
        work(new RevealQuestion($room->id, 1));

        expect($players[0]->fresh()->score)->toBe(100);
        Event::assertDispatchedTimes(QuestionRevealed::class, 1);
    });

    it('survives the job arriving after everyone answered early', function () {
        [$room, $question, $players] = openQuestion();
        foreach ($players as $player) {
            answer($player, $question, true);
        }
        at('12:00:21.000');

        work(new RevealQuestion($room->id, 1));

        Event::assertDispatchedTimes(QuestionRevealed::class, 1);
        expect($players[0]->fresh()->score)->toBe(100);
    });

    it('survives the job arriving after the host skipped on to the next question', function () {
        [$room, $question, $players] = openQuestion();
        at('12:00:21.000');
        engine()->reveal($room, 1);
        engine()->advance($room, 1, force: true);
        at('12:00:41.000');

        work(new RevealQuestion($room->id, 1)); // stale: question 2 is on screen now

        expect($room->fresh()->currentQuestion()->position)->toBe(2)->and($room->fresh()->currentQuestion()->revealed_at)->toBeNull();
        Event::assertDispatchedTimes(QuestionRevealed::class, 1);
    });

    it('tries again shortly when the job runs a moment early', function () {
        [$room] = openQuestion();
        at('12:00:20.200'); // a database queue rounds delays to whole seconds

        $job = (new RevealQuestion($room->id, 1))->withFakeQueueInteractions();
        work($job);

        $job->assertReleased(delay: 1);
        Event::assertNotDispatched(QuestionRevealed::class);
    });

    it('does not fail for a room that no longer exists', function () {
        work(new RevealQuestion(999999, 1));
        work(new AdvanceQuestion(999999, 1));

        Event::assertNotDispatched(QuestionRevealed::class);
    });
});

describe('advancing', function () {
    function revealedFirstQuestion(): array
    {
        [$room, $question, $players] = openQuestion();
        answer($players[0], $question, true);
        at('12:00:21.000');
        engine()->reveal($room, 1);

        return [$room, $question, $players];
    }

    it('starts the next question after the 5 second reveal pause', function () {
        [$room] = revealedFirstQuestion();
        at('12:00:26.000');

        $result = engine()->advance($room, 1);

        $next = $room->fresh()->currentQuestion();
        expect($result)->toBe(Transition::Done)
            ->and($next->position)->toBe(2)
            ->and($next->started_at->format('H:i:s.v'))->toBe('12:00:26.000')
            ->and($next->ends_at->format('H:i:s.v'))->toBe('12:00:46.000')
            ->and($next->revealed_at)->toBeNull();
        Event::assertDispatched(QuestionStarted::class, fn (QuestionStarted $e) => $e->broadcastWith()['question']['position'] === 2);
        Queue::assertPushed(RevealQuestion::class, fn (RevealQuestion $job) => $job->position === 2 && $job->delay->format('H:i:s.v') === '12:00:46.500');
    });

    it('is too early before the pause is over', function () {
        [$room] = revealedFirstQuestion();
        at('12:00:25.900');

        expect(engine()->advance($room, 1))->toBe(Transition::TooEarly)->and($room->fresh()->currentQuestion()->position)->toBe(1);
    });

    it('lets the host skip the pause with force', function () {
        [$room] = revealedFirstQuestion();
        at('12:00:21.500');

        expect(engine()->advance($room, 1, force: true))->toBe(Transition::Done)->and($room->fresh()->currentQuestion()->position)->toBe(2);
    });

    it('cannot skip a question that has not been revealed', function () {
        [$room] = openQuestion();
        at('12:00:10.000');

        expect(engine()->advance($room, 1, force: true))->toBe(Transition::Stale)->and($room->fresh()->currentQuestion()->position)->toBe(1);
    });

    it('moves one question at a time however often it is asked', function () {
        [$room] = revealedFirstQuestion();
        at('12:00:30.000');

        $results = [engine()->advance($room, 1), engine()->advance($room, 1), engine()->advance($room, 1, force: true)];

        expect($results)->toBe([Transition::Done, Transition::Stale, Transition::Stale])
            ->and($room->fresh()->currentQuestion()->position)->toBe(2);
        Event::assertDispatchedTimes(QuestionStarted::class, 2); // question 1 and question 2
    });

    it('survives a double click on Next question', function () {
        [$room] = revealedFirstQuestion();

        engine()->advance($room, 1, force: true);
        engine()->advance($room, 1, force: true);

        expect($room->fresh()->currentQuestion()->position)->toBe(2)
            ->and($room->roomQuestions()->whereNotNull('started_at')->count())->toBe(2);
    });

    it('survives the advance job running after the host override', function () {
        [$room] = revealedFirstQuestion();
        engine()->advance($room, 1, force: true);
        at('12:00:40.000');

        work(new AdvanceQuestion($room->id, 1));

        expect($room->fresh()->currentQuestion()->position)->toBe(2)
            ->and($room->fresh()->currentQuestion()->revealed_at)->toBeNull();
        Event::assertDispatchedTimes(QuestionStarted::class, 2);
    });

    it('survives the host override after the advance job', function () {
        [$room] = revealedFirstQuestion();
        at('12:00:26.000');
        work(new AdvanceQuestion($room->id, 1));

        engine()->advance($room, 1, force: true);

        expect($room->fresh()->currentQuestion()->position)->toBe(2);
        Event::assertDispatchedTimes(QuestionStarted::class, 2);
    });

    it('survives the advance job running twice', function () {
        [$room] = revealedFirstQuestion();
        at('12:00:30.000');

        work(new AdvanceQuestion($room->id, 1));
        work(new AdvanceQuestion($room->id, 1));

        expect($room->fresh()->currentQuestion()->position)->toBe(2);
    });

    it('tries again shortly when the advance job runs a moment early', function () {
        [$room] = revealedFirstQuestion();
        at('12:00:25.500');

        $job = (new AdvanceQuestion($room->id, 1))->withFakeQueueInteractions();
        work($job);

        $job->assertReleased(delay: 1);
    });

    it('finishes the game after the last question', function () {
        $room = gameRoom(players: 2, questions: 3);
        $players = $room->players()->orderBy('id')->get();
        startGame($room);
        foreach ([1, 2, 3] as $position) {
            $question = $room->fresh()->currentQuestion();
            answer($players[0], $question, true);
            answer($players[1], $question, $position === 1);   // early reveal
            engine()->advance($room, $position, force: true);
        }

        $room->refresh();
        expect($room->status)->toBe(RoomStatus::Finished)->and($room->finished_at)->not->toBeNull()
            ->and($players[0]->fresh()->score)->toBe(300)->and($players[1]->fresh()->score)->toBe(100);
        Event::assertDispatchedTimes(GameFinished::class, 1);
        Event::assertDispatched(GameFinished::class, fn (GameFinished $e) => $e->broadcastWith()['ranking'][0]['player']['id'] === $players[0]->id);
        Event::assertDispatchedTimes(QuestionStarted::class, 3);
    });

    it('finishes only once and does nothing afterwards', function () {
        $room = gameRoom(players: 1, questions: 3);
        $player = $room->players()->first();
        startGame($room);
        foreach ([1, 2, 3] as $position) {
            answer($player, $room->fresh()->currentQuestion());
            engine()->advance($room, $position, force: true);
        }

        $again = [engine()->advance($room, 3, force: true), engine()->advance($room, 3), engine()->tick($room->fresh())];

        expect($again)->toBe([Transition::Stale, Transition::Stale, Transition::Stale]);
        Event::assertDispatchedTimes(GameFinished::class, 1);
    });
});

describe('tick: what is due', function () {
    it('reveals an open question whose time is up', function () {
        [$room, $question] = openQuestion();
        at('12:00:21.000');

        expect(engine()->tick($room))->toBe(Transition::Done)->and($question->fresh()->revealed_at)->not->toBeNull();
    });

    it('advances a revealed question once the pause is over', function () {
        [$room, $question] = openQuestion();
        at('12:00:21.000');
        engine()->tick($room);
        at('12:00:26.000');

        expect(engine()->tick($room))->toBe(Transition::Done)->and($room->fresh()->currentQuestion()->position)->toBe(2);
    });

    it('does nothing while a question is still running or in a lobby', function () {
        [$room] = openQuestion();
        at('12:00:10.000');
        expect(engine()->tick($room))->toBe(Transition::TooEarly);

        expect(engine()->tick(gameRoom()))->toBe(Transition::Stale);
    });
});

describe('locking', function () {
    it('releases the room lock after every transition, refused or not', function () {
        [$room, $question, $players] = openQuestion();

        engine()->reveal($room, 1);                  // too early
        engine()->advance($room, 1);                 // stale
        at('12:00:21.000');
        engine()->reveal($room, 1);                  // done
        engine()->reveal($room, 1);                  // already

        $lock = Cache::lock("room:{$room->id}:game", 10);
        expect($lock->get())->toBeTrue();
        $lock->release();
    });

    it('keeps rooms independent: one room\'s transition does not touch another game', function () {
        [$one, $q1] = openQuestion();
        [$two, $q2] = openQuestion();
        at('12:00:21.000');

        engine()->reveal($one, 1);

        expect($q1->fresh()->revealed_at)->not->toBeNull()->and($q2->fresh()->revealed_at)->toBeNull();
    });
});
