<?php

use App\Events\PlayerJoined;
use App\Events\PlayerLeft;
use App\Events\PlayerReadyChanged;
use App\Events\RoomClosed;
use App\Game\JoinException;
use App\Game\PlayerIdentity;
use App\Game\RoomJoiner;
use App\Game\RoomManager;
use App\Game\RoomRoster;
use App\Game\RoomSettings;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/** Every key a player payload may have. Anything else (a token, an email, a user id) would be a leak. */
const PLAYER_KEYS = ['id', 'is_ready', 'locale', 'nickname'];

function joinerAs(?string $token = null, ?User $user = null): RoomJoiner
{
    $request = Request::create('/join', 'GET', cookies: $token ? ['lamma_guest' => $token] : []);
    if ($user) {
        $request->setUserResolver(fn () => $user);
    }

    return new RoomJoiner(new PlayerIdentity($request));
}

describe('every lobby event', function () {
    it('is sent straight away, not queued, so no worker is needed', function (string $event) {
        expect(is_subclass_of($event, ShouldBroadcastNow::class))->toBeTrue();
    })->with([PlayerJoined::class, PlayerLeft::class, PlayerReadyChanged::class, RoomClosed::class]);

    it('goes to presence-room.{code}, which only the host and participants can join', function () {
        $room = Room::factory()->create(['code' => 'K7MP9Z']);
        $player = RoomPlayer::factory()->for($room)->create();

        foreach ([new PlayerJoined($room, $player), new PlayerLeft($room, $player), new PlayerReadyChanged($room, $player), new RoomClosed($room)] as $event) {
            $channels = $event->broadcastOn();

            expect($channels)->toHaveCount(1)
                ->and($channels[0])->toBeInstanceOf(PresenceChannel::class)
                ->and($channels[0]->name)->toBe('presence-room.K7MP9Z');
        }
    });

    it('carries only id, nickname, locale and is_ready for a player', function () {
        $room = Room::factory()->create();
        $guest = RoomPlayer::factory()->for($room)->create(['guest_token' => str_repeat('s', 64), 'nickname' => 'Sara', 'locale' => 'ar', 'is_ready' => true]);
        $account = RoomPlayer::factory()->for($room)->forUser(User::factory()->create(['email' => 'secret@example.com']))->create();

        foreach ([$guest, $account] as $player) {
            $payload = (new PlayerJoined($room, $player))->broadcastWith();

            expect(array_keys($payload))->toBe(['code', 'server_time', 'player'])
                ->and(array_keys($payload['player']))->toEqualCanonicalizing(PLAYER_KEYS)
                ->and(json_encode($payload))->not->toContain(str_repeat('s', 64))->not->toContain('secret@example.com')->not->toContain('user_id')->not->toContain('guest_token');
        }
        expect((new PlayerJoined($room, $guest))->broadcastWith()['player'])->toBe(['id' => $guest->id, 'nickname' => 'Sara', 'locale' => 'ar', 'is_ready' => true]);
    });

    it('carries only the code for a closed room', function () {
        $payload = (new RoomClosed(Room::factory()->create(['code' => 'K7MP9Z'])))->broadcastWith();

        expect(array_keys($payload))->toBe(['code', 'server_time'])->and($payload['code'])->toBe('K7MP9Z')->and($payload['server_time'])->toBeInt();
    });

    it('keeps its snapshot after the player row is deleted', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['nickname' => 'Gone']);

        $event = new PlayerLeft($room, $player);
        $player->delete();

        expect($event->broadcastWith()['player']['nickname'])->toBe('Gone');
    });

    it('reaches the broadcaster with the channel, the event name and the payload', function () {
        $sent = new ArrayObject;
        config(['broadcasting.default' => 'capture', 'broadcasting.connections.capture' => ['driver' => 'capture']]);
        app(BroadcastManager::class)->extend('capture', fn () => new class($sent) extends Broadcaster
        {
            public function __construct(private ArrayObject $sent) {}

            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->sent[] = ['channels' => array_map('strval', $channels), 'event' => $event, 'payload' => $payload];
            }
        });
        $room = Room::factory()->create(['code' => 'K7MP9Z']);
        $player = RoomPlayer::factory()->for($room)->create(['nickname' => 'Sara', 'is_ready' => true]);

        broadcast(new PlayerReadyChanged($room, $player));

        expect($sent)->toHaveCount(1)
            ->and($sent[0]['channels'])->toBe(['presence-room.K7MP9Z'])
            ->and($sent[0]['event'])->toBe('App\Events\PlayerReadyChanged')
            ->and($sent[0]['payload']['player'])->toBe(['id' => $player->id, 'nickname' => 'Sara', 'locale' => $player->locale, 'is_ready' => true])
            ->and(json_encode($sent[0]['payload']))->not->toContain('guest_token');
    });
});

describe('PlayerJoined', function () {
    beforeEach(fn () => Event::fake([PlayerJoined::class]));

    it('is broadcast when a new player joins, with their snapshot', function () {
        $room = Room::factory()->create();

        $player = joinerAs()->join($room->code, 'Sara', 'ar');

        Event::assertDispatched(PlayerJoined::class, fn (PlayerJoined $e) => $e->roomCode === $room->code
            && $e->broadcastWith()['player'] === ['id' => $player->id, 'nickname' => 'Sara', 'locale' => 'ar', 'is_ready' => false]);
        Event::assertDispatchedTimes(PlayerJoined::class, 1);
    });

    it('is not broadcast again when someone rejoins', function () {
        $room = Room::factory()->create();
        $token = str_repeat('r', 64);
        joinerAs($token)->join($room->code, 'Sara', 'en');

        joinerAs($token)->join($room->code, 'Sara', 'en');

        Event::assertDispatchedTimes(PlayerJoined::class, 1);
    });

    it('is not broadcast when the join is refused', function () {
        $room = Room::factory()->playing()->create();

        try {
            joinerAs()->join($room->code, 'Sara', 'en');
        } catch (JoinException) {
        }

        Event::assertNotDispatched(PlayerJoined::class);
    });

    it('starts the new player as not yet connected', function () {
        $room = Room::factory()->create();

        $player = joinerAs()->join($room->code, 'Sara', 'en');

        expect($player->left_at)->not->toBeNull()
            ->and(RoomPlayer::connected()->whereKey($player->id)->exists())->toBeFalse();
    });
});

describe('PlayerReadyChanged', function () {
    beforeEach(fn () => Event::fake([PlayerReadyChanged::class]));

    it('is broadcast with the new value each time Ready is toggled', function () {
        $player = RoomPlayer::factory()->for(Room::factory()->create())->create(['is_ready' => false]);

        app(RoomRoster::class)->toggleReady($player);
        app(RoomRoster::class)->toggleReady($player);

        Event::assertDispatchedTimes(PlayerReadyChanged::class, 2);
        $values = Event::dispatched(PlayerReadyChanged::class)->map(fn ($call) => $call[0]->broadcastWith()['player']['is_ready'])->all();
        expect($values)->toBe([true, false]);
    });

    it('is not broadcast when nothing changed or the game has started', function () {
        $player = RoomPlayer::factory()->for(Room::factory()->create())->create(['is_ready' => true]);
        app(RoomRoster::class)->setReady($player, true);

        $late = RoomPlayer::factory()->for(Room::factory()->playing()->create())->create(['is_ready' => false]);
        app(RoomRoster::class)->setReady($late, true);

        Event::assertNotDispatched(PlayerReadyChanged::class);
    });
});

describe('PlayerLeft', function () {
    beforeEach(fn () => Event::fake([PlayerLeft::class]));

    it('is broadcast when a player leaves', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['nickname' => 'Sara']);

        app(RoomRoster::class)->leave($player);

        Event::assertDispatched(PlayerLeft::class, fn (PlayerLeft $e) => $e->roomCode === $room->code && $e->broadcastWith()['player']['id'] === $player->id);
    });

    it('is broadcast when the host removes a player', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create();

        app(RoomRoster::class)->remove($room, $room->host, $player->id);

        Event::assertDispatchedTimes(PlayerLeft::class, 1);
    });

    it('is broadcast for each player dropped after the grace period', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->count(2)->create(['left_at' => now()->subSeconds(40)]);
        RoomPlayer::factory()->for($room)->create(['left_at' => now()->subSeconds(5)]);

        app(RoomRoster::class)->pruneDisconnected($room);

        Event::assertDispatchedTimes(PlayerLeft::class, 2);
    });
});

describe('RoomClosed', function () {
    beforeEach(fn () => Event::fake([RoomClosed::class]));

    it('is broadcast when a room is closed, once', function () {
        $room = Room::factory()->create();

        app(RoomManager::class)->close($room);
        app(RoomManager::class)->close($room->fresh());

        Event::assertDispatchedTimes(RoomClosed::class, 1);
        Event::assertDispatched(RoomClosed::class, fn (RoomClosed $e) => $e->roomCode === $room->code);
    });

    it('is broadcast to the phones of the old room when the host starts a new one', function () {
        $host = User::factory()->create();
        $old = Room::factory()->for($host, 'host')->create();
        $category = categoryWithQuestions(12);

        app(RoomManager::class)->create($host, new RoomSettings(categoryIds: [$category->id]));

        Event::assertDispatched(RoomClosed::class, fn (RoomClosed $e) => $e->roomCode === $old->code);
    });
});
