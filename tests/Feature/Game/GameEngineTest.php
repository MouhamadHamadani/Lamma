<?php

use App\Enums\RoomStatus;
use App\Events\GameStarted;
use App\Events\QuestionStarted;
use App\Game\GameEngine;
use App\Game\GameStartException;
use App\Game\Transition;
use App\Jobs\RevealQuestion;
use App\Jobs\StartQuestion;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

function startError(Room $room, ?User $by = null): string
{
    try {
        app(GameEngine::class)->start($room, $by ?? $room->host);
    } catch (GameStartException $e) {
        return $e->getMessage();
    }

    test()->fail('Expected the game not to start');
}

function readyRoom(int $players = 2): Room
{
    return gameRoom($players);
}

beforeEach(function () {
    Event::fake([GameStarted::class]);
    Queue::fake();
});

describe('starting', function () {
    it('moves a lobby with everyone ready to playing and sets the start time', function () {
        $room = readyRoom();

        $started = app(GameEngine::class)->start($room, $room->host);

        expect($started->status)->toBe(RoomStatus::Playing)->and($started->started_at)->not->toBeNull()
            ->and($room->fresh()->status)->toBe(RoomStatus::Playing);
    });

    it('works with a single connected player', function () {
        $room = readyRoom(1);

        expect(app(GameEngine::class)->start($room, $room->host)->status)->toBe(RoomStatus::Playing);
    });

    it('broadcasts GameStarted once on the room channel', function () {
        $room = readyRoom();

        app(GameEngine::class)->start($room, $room->host);

        Event::assertDispatchedTimes(GameStarted::class, 1);
        Event::assertDispatched(GameStarted::class, fn (GameStarted $e) => $e->roomCode === $room->code
            && $e->broadcastOn()[0]->name === 'presence-room.'.$room->code
            && $e->broadcastWith()['code'] === $room->code);
    });

    it('is not blocked by players who are disconnected', function () {
        $room = readyRoom(2);
        RoomPlayer::factory()->for($room)->create(['is_ready' => false, 'left_at' => now()->subSeconds(10)]);

        expect(app(GameEngine::class)->start($room, $room->host)->status)->toBe(RoomStatus::Playing);
    });
});

describe('rules', function () {
    it('needs the room host', function () {
        $room = readyRoom();

        expect(startError($room, User::factory()->create()))->toBe('Only the host can start the game.');
        expect($room->fresh()->status)->toBe(RoomStatus::Lobby);
        Event::assertNotDispatched(GameStarted::class);
    });

    it('needs the lobby', function (string $state) {
        $room = Room::factory()->{$state}()->create();
        RoomPlayer::factory()->for($room)->create(['is_ready' => true, 'left_at' => null]);

        expect(startError($room))->toBe('This game has already started');
        Event::assertNotDispatched(GameStarted::class);
    })->with(['playing', 'finished']);

    it('needs at least one player', function () {
        expect(startError(Room::factory()->create()))->toBe('Wait for at least one player to connect.');
    });

    it('needs at least one connected player', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['is_ready' => true, 'left_at' => now()->subSeconds(5)]);

        expect(startError($room))->toBe('Wait for at least one player to connect.');
    });

    it('needs every connected player to be ready', function () {
        $room = readyRoom(2);
        RoomPlayer::factory()->for($room)->create(['is_ready' => false, 'left_at' => null]);

        expect(startError($room))->toBe('Everyone needs to tap Ready first.');
        expect($room->fresh()->status)->toBe(RoomStatus::Lobby);
        Event::assertNotDispatched(GameStarted::class);
    });

    it('looks at the database, not at the room object it was handed', function () {
        $stale = readyRoom(1);
        RoomPlayer::query()->update(['is_ready' => false]);

        expect(startError($stale))->toBe('Everyone needs to tap Ready first.');
    });

    it('has the room already moved on by another request, not start it twice', function () {
        $room = readyRoom();
        $copy = Room::find($room->id);

        app(GameEngine::class)->start($room, $room->host);

        expect(startError($copy))->toBe('This game has already started');
        Event::assertDispatchedTimes(GameStarted::class, 1);
    });
});

describe('double clicks', function () {
    it('starts exactly once when called twice in a row', function () {
        $room = readyRoom();

        app(GameEngine::class)->start($room, $room->host);
        $second = startError($room);

        expect($second)->toBe('This game has already started');
        Event::assertDispatchedTimes(GameStarted::class, 1);
    });

    it('refuses while another start holds the lock, and changes nothing', function () {
        $room = readyRoom();
        $held = Cache::lock("room:{$room->id}:start", 10);
        $held->get();

        expect(startError($room))->toBe('The game is already starting.');

        expect($room->fresh()->status)->toBe(RoomStatus::Lobby);
        Event::assertNotDispatched(GameStarted::class);
        $held->release();
    });

    it('does not hold up another room', function () {
        $busy = readyRoom();
        $other = readyRoom();
        $held = Cache::lock("room:{$busy->id}:start", 10);
        $held->get();

        expect(app(GameEngine::class)->start($other, $other->host)->status)->toBe(RoomStatus::Playing);
        $held->release();
    });

    it('releases the lock after a refusal, so the host can fix things and try again', function () {
        $room = readyRoom(1);
        RoomPlayer::query()->update(['is_ready' => false]);

        expect(startError($room))->toBe('Everyone needs to tap Ready first.');
        RoomPlayer::query()->update(['is_ready' => true]);

        expect(app(GameEngine::class)->start($room, $room->host)->status)->toBe(RoomStatus::Playing);
    });

    it('releases the lock after a successful start', function () {
        $room = readyRoom();
        app(GameEngine::class)->start($room, $room->host);

        $again = Cache::lock("room:{$room->id}:start", 10);

        expect($again->get())->toBeTrue();
        $again->release();
    });
});

describe('the countdown (3 · 2 · 1 · Go! before question 1)', function () {
    beforeEach(function () {
        Event::fake([GameStarted::class, QuestionStarted::class]);
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.250'));
    });

    it('starts a three second countdown instead of opening question 1', function () {
        $room = readyRoom();

        $started = app(GameEngine::class)->start($room, $room->host);

        expect($started->countdown_ends_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-01 12:00:03.250')
            ->and($room->fresh()->countdown_ends_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-01 12:00:03.250') // milliseconds survive the database
            ->and($room->fresh()->currentQuestion())->toBeNull()
            ->and($room->roomQuestions()->count())->toBe(3)
            ->and($room->roomQuestions()->whereNotNull('started_at')->count())->toBe(0);
        Event::assertNotDispatched(QuestionStarted::class);
    });

    it('tells every screen when it ends, in the GameStarted payload', function () {
        $room = readyRoom();

        app(GameEngine::class)->start($room, $room->host);

        Event::assertDispatched(GameStarted::class, fn (GameStarted $e) => $e->broadcastWith()['countdown_ends_at'] === '2026-10-01T12:00:03.250Z' && $e->broadcastWith()['server_time'] === now()->getTimestampMs());
    });

    it('schedules question 1 for the end of the countdown', function () {
        $room = readyRoom();

        app(GameEngine::class)->start($room, $room->host);

        Queue::assertPushed(StartQuestion::class, fn (StartQuestion $job) => $job->roomId === $room->id && CarbonImmutable::instance($job->delay)->format('H:i:s.v') === '12:00:03.250');
    });

    it('does not open question 1 early, whoever asks', function () {
        $room = readyRoom();
        $engine = app(GameEngine::class);
        $engine->start($room, $room->host);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:03.000'));

        expect($engine->startQuestion($room))->toBe(Transition::TooEarly)
            ->and($engine->tick($room))->toBe(Transition::TooEarly)
            ->and($room->fresh()->currentQuestion())->toBeNull();
        Event::assertNotDispatched(QuestionStarted::class);
    });

    it('opens question 1 when the countdown ends, with its own clock starting then', function () {
        $room = readyRoom();
        $engine = app(GameEngine::class);
        $engine->start($room, $room->host);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:03.250'));

        expect($engine->startQuestion($room))->toBe(Transition::Done);

        $question = $room->fresh()->currentQuestion();
        expect($question->position)->toBe(1)
            ->and($question->started_at->format('H:i:s.v'))->toBe('12:00:03.250')
            ->and($question->ends_at->format('H:i:s.v'))->toBe('12:00:23.250');
        Event::assertDispatchedTimes(QuestionStarted::class, 1);
        Queue::assertPushed(RevealQuestion::class, 1);
    });

    it('opens it exactly once when the job and the host screen both ask', function () {
        $room = readyRoom();
        $engine = app(GameEngine::class);
        $engine->start($room, $room->host);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:04.000'));

        expect($engine->startQuestion($room))->toBe(Transition::Done)
            ->and($engine->startQuestion($room))->toBe(Transition::Already)
            ->and($engine->tick($room))->toBe(Transition::TooEarly); // tick now moves on to the reveal, which is not due yet

        Event::assertDispatchedTimes(QuestionStarted::class, 1);
        Queue::assertPushed(RevealQuestion::class, 1);
    });

    it('is started by the host screen\'s tick when the queue worker is not running', function () {
        $room = readyRoom();
        $engine = app(GameEngine::class);
        $engine->start($room, $room->host);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:03.400'));

        expect($engine->tick($room))->toBe(Transition::Done)
            ->and($room->fresh()->currentQuestion()->position)->toBe(1);
    });

    it('does nothing for a room that was closed during the countdown, or never started', function () {
        $engine = app(GameEngine::class);
        $closed = readyRoom();
        $engine->start($closed, $closed->host);
        $closed->update(['status' => RoomStatus::Finished]);
        $lobby = readyRoom();

        expect($engine->startQuestion($closed, force: true))->toBe(Transition::Stale)
            ->and($engine->startQuestion($lobby, force: true))->toBe(Transition::Stale)
            ->and($engine->tick($lobby))->toBe(Transition::Stale);
        Event::assertNotDispatched(QuestionStarted::class);
    });

    it('treats a game that started before countdowns existed as past its countdown', function () {
        $room = Room::factory()->playing()->create(['countdown_ends_at' => null]);

        expect(app(GameEngine::class)->countdownEndsAt($room)->format('H:i:s'))->toBe('12:00:00');
    });

    it('has a job that opens question 1 when it runs on time, tries again when early, and ignores a vanished room', function () {
        $room = readyRoom();
        $engine = app(GameEngine::class);
        $engine->start($room, $room->host);

        $early = Mockery::mock(StartQuestion::class)->makePartial();
        $early->__construct($room->id);
        $early->shouldReceive('release')->once()->with(1);
        $early->handle($engine);
        expect($room->fresh()->currentQuestion())->toBeNull();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:03.500'));
        (new StartQuestion($room->id))->handle($engine);
        expect($room->fresh()->currentQuestion()->position)->toBe(1);

        (new StartQuestion(999999))->handle($engine);
        Event::assertDispatchedTimes(QuestionStarted::class, 1);
    });
});
