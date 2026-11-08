<?php

use App\Events\GameStarted;
use App\Events\PlayerJoined;
use App\Events\QuestionStarted;
use App\Game\RoomPresence;
use App\Livewire\Host\HostLobby;
use App\Livewire\Player\PlayerLobby;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
 * Phase 14.1, problem 1. Reproduced first (a feature test at the real /broadcasting/auth route, then live with Reverb and two tabs of one browser): when the browser that
 * runs the host screen also holds a player row in the room (a second tab on the same laptop: how a host tests alone, and a demo host who "joins from this laptop"), the
 * presence channel tried the "player" guard first and the HOST SCREEN became that player ("player:4", is_host false). Live, the channel then counted ONE member for the two
 * tabs, so closing the player tab never marked the player gone (left_at stayed null): a ghost that was never ready, "0 of 1 ready", Start locked.
 *
 * The fix: the host screen's Echo sends X-Lamma-Role: host, and the new "host" guard (first on the channel) believes it only when the request really is the room's host.
 */

const ROLE_GUEST = 'r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1r1';

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
        'broadcasting.connections.reverb.options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
    ]);
    app(BroadcastManager::class)->purge();
    require base_path('routes/channels.php');
    $this->withCredentials();
});

/** What Echo's channel auth request is, from this browser: $role is the header value the page sends (null = none), $cookie the guest or demo-host cookies it holds. */
function roleAuth(string $code, ?string $role = null, array $cookies = []): TestResponse
{
    // A real request is a fresh process: the request guards would keep the previous request's user (not "web": that one is the test's own actingAs).
    foreach (['host', 'player', 'demo'] as $guard) {
        Auth::guard($guard)->forgetUser();
    }

    $test = test()->flushHeaders();
    foreach ($cookies as $name => $value) {
        $test = $test->withCookie($name, $value);
    }
    if ($role !== null) {
        $test = $test->withHeader(RoomPresence::ROLE_HEADER, $role);
    }

    return $test->postJson('/broadcasting/auth', ['channel_name' => "presence-room.{$code}", 'socket_id' => '1234.5678']);
}

/** The member the channel made of this request. */
function roleMember(TestResponse $response): array
{
    $data = json_decode($response->assertOk()->json('channel_data'), true);

    return ['user_id' => $data['user_id'], ...$data['user_info']];
}

/** A real room whose host is also, in the same browser, a player (a row with the host's account AND the browser's guest token: the host's own phone, or a second tab). */
function roomWithHostPlayer(): array
{
    $room = Room::factory()->create();
    $player = RoomPlayer::factory()->for($room)->create(['user_id' => $room->host_id, 'guest_token' => ROLE_GUEST, 'nickname' => 'Hadi']);

    return [$room, $player];
}

describe('a browser that is both the host and a player (a real room)', function () {
    it('is the HOST on the host screen, which says so', function () {
        [$room, $player] = roomWithHostPlayer();
        test()->actingAs($room->host);

        $member = roleMember(roleAuth($room->code, 'host', ['lamma_guest' => ROLE_GUEST]));

        expect($member)->toMatchArray(['user_id' => $room->host_id, 'room_player_id' => null, 'is_host' => true, 'nickname' => $room->host->name]);
    });

    it('is still the PLAYER on the phone tab, which sends nothing (the Phase 12 rule)', function () {
        [$room, $player] = roomWithHostPlayer();
        test()->actingAs($room->host);

        $member = roleMember(roleAuth($room->code, null, ['lamma_guest' => ROLE_GUEST]));

        expect($member)->toMatchArray(['user_id' => "player:{$player->id}", 'room_player_id' => $player->id, 'is_host' => false, 'nickname' => 'Hadi']);
    });

    it('makes two different members of the two tabs, so closing one is noticed', function () {
        [$room, $player] = roomWithHostPlayer();
        test()->actingAs($room->host);

        $screen = roleMember(roleAuth($room->code, 'host', ['lamma_guest' => ROLE_GUEST]));
        $tab = roleMember(roleAuth($room->code, null, ['lamma_guest' => ROLE_GUEST]));

        expect($screen['user_id'])->not->toBe($tab['user_id'])->and($screen['is_host'])->toBeTrue()->and($tab['is_host'])->toBeFalse();
    });

    it('is the host on the host screen whatever the language setting and letter case of the code', function () {
        [$room, $player] = roomWithHostPlayer();
        test()->actingAs($room->host);

        expect(roleMember(roleAuth(strtolower($room->code), 'host', ['lamma_guest' => ROLE_GUEST]))['is_host'])->toBeTrue();
    });

    it('is the host on a host screen that has no player row at all, with or without the header', function () {
        $room = Room::factory()->create();
        test()->actingAs($room->host);

        expect(roleMember(roleAuth($room->code, 'host'))['is_host'])->toBeTrue();
        expect(roleMember(roleAuth($room->code))['is_host'])->toBeTrue();
    });
});

describe('a browser that is both the host and a player (a demo room)', function () {
    it('is the demo host on the host screen, and the player on the phone tab', function () {
        $room = demoGame(humans: 0);
        $player = RoomPlayer::factory()->for($room)->create(['guest_token' => ROLE_GUEST, 'nickname' => 'Laila', 'is_ready' => true]);
        $cookies = ['lamma_demo_host' => DEMO_TOKEN, 'lamma_guest' => ROLE_GUEST];

        $screen = roleMember(roleAuth($room->code, 'host', $cookies));
        $tab = roleMember(roleAuth($room->code, null, $cookies));

        expect($screen)->toMatchArray(['user_id' => "demo-host:{$room->id}", 'room_player_id' => null, 'is_host' => true])
            ->and($tab)->toMatchArray(['user_id' => "player:{$player->id}", 'room_player_id' => $player->id, 'is_host' => false]);
    });

    it('is the demo host on a host screen alone', function () {
        $room = demoGame(humans: 0);

        expect(roleMember(roleAuth($room->code, 'host', ['lamma_demo_host' => DEMO_TOKEN]))['is_host'])->toBeTrue();
    });
});

describe('the header grants nothing by itself', function () {
    it('does not make a phone the host: a guest with a row and the header is still that player', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['guest_token' => ROLE_GUEST]);

        $member = roleMember(roleAuth($room->code, 'host', ['lamma_guest' => ROLE_GUEST]));

        expect($member)->toMatchArray(['user_id' => "player:{$player->id}", 'is_host' => false]);
    });

    it('does not make a logged-in player the host', function () {
        $room = Room::factory()->create();
        $user = User::factory()->create();
        $player = RoomPlayer::factory()->for($room)->forUser($user)->create();
        test()->actingAs($user);

        $member = roleMember(roleAuth($room->code, 'host'));

        // A logged-in player (no guest cookie) is the account itself on the channel, with their row's id: never the host.
        expect($member)->toMatchArray(['user_id' => $user->id, 'room_player_id' => $player->id, 'is_host' => false]);
    });

    it('does not let a stranger in with the header', function () {
        $room = Room::factory()->create();
        test()->actingAs(User::factory()->create());

        roleAuth($room->code, 'host')->assertForbidden();
        roleAuth($room->code, 'host', ['lamma_guest' => ROLE_GUEST])->assertForbidden();
    });

    it('does not let a demo cookie of another room (or a real room) be the host', function () {
        $demo = demoGame(humans: 0);
        $other = demoGame(humans: 0, token: OTHER_DEMO_TOKEN);
        $real = Room::factory()->create();

        roleAuth($demo->code, 'host', ['lamma_demo_host' => OTHER_DEMO_TOKEN])->assertForbidden();
        roleAuth($real->code, 'host', ['lamma_demo_host' => DEMO_TOKEN])->assertForbidden();
        expect(roleMember(roleAuth($other->code, 'host', ['lamma_demo_host' => OTHER_DEMO_TOKEN]))['is_host'])->toBeTrue();
    });

    it('is checked against the room of the channel: the host of room A sending it for room B is not the host of B', function () {
        $a = Room::factory()->create();
        $b = Room::factory()->create();
        $inB = RoomPlayer::factory()->for($b)->create(['user_id' => $a->host_id, 'guest_token' => ROLE_GUEST, 'nickname' => 'Hadi']);
        test()->actingAs($a->host);

        // In B they are a player, so that is what they are; in a room C they are nothing at all.
        expect(roleMember(roleAuth($b->code, 'host', ['lamma_guest' => ROLE_GUEST])))->toMatchArray(['user_id' => "player:{$inB->id}", 'is_host' => false]);
        roleAuth(Room::factory()->create()->code, 'host')->assertForbidden();
        expect(roleMember(roleAuth($a->code, 'host'))['is_host'])->toBeTrue();
    });

    it('only believes the exact value "host"', function (string $value) {
        [$room, $player] = roomWithHostPlayer();
        test()->actingAs($room->host);

        expect(roleMember(roleAuth($room->code, $value, ['lamma_guest' => ROLE_GUEST]))['is_host'])->toBeFalse();
    })->with(['Host', 'HOST', 'player', 'true', '1', ' host', 'host ', 'host,player']);

    it('does not trust a field in the body instead of the header', function () {
        [$room, $player] = roomWithHostPlayer();
        test()->actingAs($room->host);
        Auth::guard('player')->forgetUser();

        $response = test()->withCookie('lamma_guest', ROLE_GUEST)->postJson('/broadcasting/auth', ['channel_name' => "presence-room.{$room->code}", 'socket_id' => '1.2', 'role' => 'host', 'X-Lamma-Role' => 'host']);

        expect(roleMember($response)['is_host'])->toBeFalse();
    });
});

describe('the host\'s own phone, a different device (Phase 12)', function () {
    it('is still a player: logged in as the host with a guest row, no header', function () {
        [$room, $player] = roomWithHostPlayer();
        test()->actingAs($room->host);

        expect(roleMember(roleAuth($room->code, null, ['lamma_guest' => ROLE_GUEST])))->toMatchArray(['user_id' => "player:{$player->id}", 'is_host' => false]);
    });

    it('is a player, and the big screen of another device is the host, at the same time', function () {
        [$room, $player] = roomWithHostPlayer();

        // The big screen: logged in on the laptop, no row for this device, header.
        test()->actingAs($room->host);
        expect(roleMember(roleAuth($room->code, 'host'))['is_host'])->toBeTrue();
        // The phone: same account, the guest cookie of the row, no header.
        expect(roleMember(roleAuth($room->code, null, ['lamma_guest' => ROLE_GUEST]))['room_player_id'])->toBe($player->id);
    });
});

describe('what the screens send', function () {
    beforeEach(function () {
        Queue::fake();
        Event::fake([GameStarted::class, QuestionStarted::class, PlayerJoined::class]);
    });

    it('marks only the host screen: <html data-realtime-role="host"> on /host/{code}, and on nothing a phone opens', function () {
        [$room, $player] = roomWithHostPlayer();

        $host = Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->html();
        $phone = Livewire::withCookie('lamma_guest', ROLE_GUEST)->actingAs($room->host)->test(PlayerLobby::class, ['room' => $room])->html();
        $join = test()->get('/join')->getContent();

        expect($host)->not->toBeEmpty();
        test()->actingAs($room->host)->get(route('host.lobby', $room->code))->assertOk()->assertSee('data-realtime-role="host"', false);
        test()->withCookie('lamma_guest', ROLE_GUEST)->get(route('play', $room->code))->assertOk()->assertSee('data-realtime', false)->assertDontSee('data-realtime-role', false);
        expect($join)->not->toContain('data-realtime-role');
    });

    it('is marked on a demo host screen too', function () {
        $room = demoGame();

        test()->withCookie('lamma_demo_host', DEMO_TOKEN)->get(route('host.lobby', $room->code))->assertOk()->assertSee('data-realtime-role="host"', false);
    });

    it('is sent by Echo only when the page says host, and nothing else changes in the Echo options', function () {
        $echo = file_get_contents(resource_path('js/echo.js'));

        expect($echo)->toContain('document.documentElement.dataset.realtimeRole')->toContain("role === 'host' ? { 'X-Lamma-Role': 'host' } : {}")
            ->and($echo)->toContain("broadcaster: 'reverb'")->and(RoomPresence::ROLE_HEADER)->toBe('X-Lamma-Role');
    });

    it('lets the host start a game while a player tab of the same browser is ready', function () {
        $room = gameRoom(players: 0);
        $player = RoomPlayer::factory()->for($room)->create(['user_id' => $room->host_id, 'guest_token' => ROLE_GUEST, 'nickname' => 'Hadi', 'is_ready' => true, 'left_at' => null]);

        // The presence list the host screen gets: itself (the host, no player id) and the player tab (its own member).
        $page = Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room]);
        $page->call('presenceHere', [['room_player_id' => null, 'is_host' => true], ['room_player_id' => $player->id, 'is_host' => false]]);

        expect($player->fresh()->left_at)->toBeNull()->and($page->viewData('readyCount'))->toBe(1)->and($page->viewData('connectedCount'))->toBe(1)->and($page->viewData('canStart'))->toBeTrue();
        $page->call('start');
        expect($room->fresh()->status->value)->toBe('playing');
    });

    it('marks the player gone when their tab closes, because the two tabs are two members', function () {
        $room = gameRoom(players: 0);
        $player = RoomPlayer::factory()->for($room)->create(['user_id' => $room->host_id, 'guest_token' => ROLE_GUEST, 'is_ready' => true, 'left_at' => null]);
        $page = Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room]);

        // The player tab left the channel; the host screen (its own member) is still there.
        $page->call('presenceLeaving', ['room_player_id' => $player->id]);

        expect($player->fresh()->left_at)->not->toBeNull();
    });
});
