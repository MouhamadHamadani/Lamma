<?php

use App\Enums\RoomStatus;
use App\Events\GameStarted;
use App\Game\GameEngine;
use App\Game\GameStartException;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

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
    $room = Room::factory()->create();
    RoomPlayer::factory()->for($room)->count($players)->create(['is_ready' => true, 'left_at' => null]);

    return $room;
}

beforeEach(fn () => Event::fake([GameStarted::class]));

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
            && $e->broadcastWith() === ['code' => $room->code]);
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
