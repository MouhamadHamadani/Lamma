<?php

use App\Enums\HostScreenLocale;
use App\Game\RoomSettings;
use App\Models\Admin;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

const GUEST_TOKEN = 'g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0g0';

beforeEach(function () {
    // The test suite broadcasts to "null". Channel auth is signed locally by the Reverb driver, no server needed.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
        'broadcasting.connections.reverb.options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
    ]);
    app(BroadcastManager::class)->purge();
    require base_path('routes/channels.php'); // register the channels on the reverb driver

    $this->withCredentials();
});

/** POST /broadcasting/auth the way Echo does for presence-room.CODE. */
function authorizeChannel(string $code, array $cookies = []): TestResponse
{
    // A real request is a fresh process; here the request guard would keep the previous request's player.
    Auth::guard('player')->forgetUser();

    $test = test();
    foreach ($cookies as $name => $value) {
        $test = $test->withCookie($name, $value);
    }

    return $test->postJson('/broadcasting/auth', ['channel_name' => "presence-room.{$code}", 'socket_id' => '1234.5678']);
}

function member(TestResponse $response): array
{
    $channelData = json_decode($response->json('channel_data'), true);

    return ['user_id' => $channelData['user_id'], ...$channelData['user_info']];
}

describe('the room host', function () {
    it('may join and is marked as the host, with no player id', function () {
        $room = Room::factory()->create();

        test()->actingAs($room->host);
        $response = authorizeChannel($room->code)->assertOk();

        expect(member($response))->toMatchArray([
            'user_id' => $room->host->id,
            'room_player_id' => null,
            'nickname' => $room->host->name,
            'is_host' => true,
        ]);
        expect($response->json('auth'))->toStartWith('test-key:');
    });

    it('gets the room language for the host screen', function (HostScreenLocale $setting, string $locale) {
        $room = Room::factory()->create(['settings' => new RoomSettings(hostScreenLocale: $setting)]);

        test()->actingAs($room->host);

        expect(member(authorizeChannel($room->code)->assertOk())['locale'])->toBe($locale);
    })->with([[HostScreenLocale::Ar, 'ar'], [HostScreenLocale::En, 'en'], [HostScreenLocale::Both, 'en']]);

    it('may join with the code in any letter case', function () {
        $room = Room::factory()->create();

        test()->actingAs($room->host);

        authorizeChannel(strtolower($room->code))->assertOk();
    });
});

describe('a guest player', function () {
    it('may join the room they are in, authenticated by the lamma_guest cookie alone', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['guest_token' => GUEST_TOKEN, 'nickname' => 'Sara', 'locale' => 'ar']);

        $response = authorizeChannel($room->code, ['lamma_guest' => GUEST_TOKEN])->assertOk();

        expect(member($response))->toMatchArray([
            'user_id' => "player:{$player->id}",
            'room_player_id' => $player->id,
            'nickname' => 'Sara',
            'locale' => 'ar',
            'is_host' => false,
        ]);
    });

    it('is told apart when they are in several rooms: the channel picks the row', function () {
        $first = Room::factory()->create();
        $second = Room::factory()->create();
        $inFirst = RoomPlayer::factory()->for($first)->create(['guest_token' => GUEST_TOKEN, 'nickname' => 'Sara']);
        $inSecond = RoomPlayer::factory()->for($second)->create(['guest_token' => GUEST_TOKEN, 'nickname' => 'Sarah']);

        expect(member(authorizeChannel($first->code, ['lamma_guest' => GUEST_TOKEN])->assertOk())['room_player_id'])->toBe($inFirst->id);
        expect(member(authorizeChannel($second->code, ['lamma_guest' => GUEST_TOKEN])->assertOk())['room_player_id'])->toBe($inSecond->id);
    });

    it('may not join a room they are not in, even holding a valid token for another room', function () {
        $theirs = Room::factory()->create();
        $other = Room::factory()->create();
        RoomPlayer::factory()->for($theirs)->create(['guest_token' => GUEST_TOKEN]);

        authorizeChannel($other->code, ['lamma_guest' => GUEST_TOKEN])->assertForbidden();
    });

    it('may not join with another device\'s token', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => GUEST_TOKEN]);

        authorizeChannel($room->code, ['lamma_guest' => str_repeat('z', 64)])->assertForbidden();
    });

    it('may not join without the cookie', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => GUEST_TOKEN]);

        authorizeChannel($room->code)->assertForbidden();
    });

    it('may not join with a malformed cookie', function (string $cookie) {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => GUEST_TOKEN]);

        authorizeChannel($room->code, ['lamma_guest' => $cookie])->assertForbidden();
    })->with(['short' => 'abc', 'symbols' => '%%%%%%%%', 'empty' => '']);

    it('still joins after their row was claimed by an account, on the device that played', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => GUEST_TOKEN, 'user_id' => User::factory()->create()->id]);

        authorizeChannel($room->code, ['lamma_guest' => GUEST_TOKEN])->assertOk();
    });
});

describe('a logged-in player', function () {
    it('may join the room they are in, as their user', function () {
        $room = Room::factory()->create();
        $user = User::factory()->create();
        $player = RoomPlayer::factory()->for($room)->forUser($user)->create(['nickname' => 'Layla']);

        test()->actingAs($user);
        $response = authorizeChannel($room->code)->assertOk();

        expect(member($response))->toMatchArray(['user_id' => $user->id, 'room_player_id' => $player->id, 'nickname' => 'Layla', 'is_host' => false]);
    });

    it('may join with the unclaimed guest row from this device', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['guest_token' => GUEST_TOKEN]);

        test()->actingAs(User::factory()->create());

        expect(member(authorizeChannel($room->code, ['lamma_guest' => GUEST_TOKEN])->assertOk())['room_player_id'])->toBe($player->id);
    });

    it('may not join a room they are not in', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for(Room::factory()->create())->forUser($user = User::factory()->create())->create();

        test()->actingAs($user);

        authorizeChannel($room->code)->assertForbidden();
    });

    it('may not join as another account\'s claimed guest row', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => GUEST_TOKEN, 'user_id' => User::factory()->create()->id]);

        test()->actingAs(User::factory()->create());

        authorizeChannel($room->code, ['lamma_guest' => GUEST_TOKEN])->assertForbidden();
    });
});

describe('everyone else', function () {
    it('a random logged-in user may not join', function () {
        $room = Room::factory()->create();

        test()->actingAs(User::factory()->create());

        authorizeChannel($room->code)->assertForbidden();
    });

    it('a visitor with no login and no cookie may not join', function () {
        authorizeChannel(Room::factory()->create()->code)->assertForbidden();
    });

    it('an admin-guard login may not join', function () {
        $room = Room::factory()->create();

        test()->actingAs(Admin::factory()->create(), 'admin');
        Auth::shouldUse('web');

        authorizeChannel($room->code)->assertForbidden();
    });

    it('an unknown room cannot be joined', function () {
        test()->actingAs(User::factory()->create());

        authorizeChannel('ZZZZZZ')->assertForbidden();
    });

    it('other people\'s channels are not opened by this one (user channel still its own)', function () {
        $user = User::factory()->create();

        test()->actingAs($user)->postJson('/broadcasting/auth', ['channel_name' => 'private-App.Models.User.'.($user->id + 1), 'socket_id' => '1.2'])->assertForbidden();
    });
});

describe('the player guard and identity', function () {
    it('is a request guard that resolves a RoomPlayer from the cookie', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['guest_token' => GUEST_TOKEN]);

        test()->withCookie('lamma_guest', GUEST_TOKEN)->post('/broadcasting/auth', ['channel_name' => "presence-room.{$room->code}", 'socket_id' => '1.2']);
        $resolved = Auth::guard('player')->user();

        expect($resolved)->toBeInstanceOf(RoomPlayer::class)->toBeInstanceOf(Authenticatable::class)
            ->and($resolved->is($player))->toBeTrue();
    });

    it('gives a RoomPlayer an id that cannot collide with a User id', function () {
        $user = User::factory()->create();
        $player = RoomPlayer::factory()->create(['id' => $user->id]);

        expect($player->getAuthIdentifier())->toBe("player:{$user->id}")->not->toBe($user->getAuthIdentifier());
    });

    it('never serializes the guest token', function () {
        $player = RoomPlayer::factory()->create(['guest_token' => GUEST_TOKEN]);

        expect($player->toArray())->not->toHaveKey('guest_token')
            ->and($player->toJson())->not->toContain(GUEST_TOKEN);
    });
});
