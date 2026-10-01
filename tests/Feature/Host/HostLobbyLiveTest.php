<?php

use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Events\GameStarted;
use App\Events\PlayerLeft;
use App\Events\RoomClosed;
use App\Game\RoomSettings;
use App\Livewire\Host\HostLobby;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function liveLobby(Room $room): Testable
{
    return Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room]);
}

function startButton(Testable $page): string
{
    // the disabled attribute itself, not wire:loading.attr="disabled"
    return preg_match('/<button[^>]*data-test="start-button"[^>]*>/s', $page->html(), $m) && preg_match('/\sdisabled[\s>]/', $m[0]) ? 'disabled' : 'enabled';
}

describe('real-time wiring', function () {
    it('listens on the room presence channel for here, joining, leaving and the lobby events', function () {
        $room = Room::factory()->create(['code' => 'K7MP9Z']);

        $listeners = liveLobby($room)->instance()->getListeners();

        foreach (['here' => 'presenceHere', 'joining' => 'presenceJoining', 'leaving' => 'presenceLeaving', 'PlayerJoined' => '$refresh', 'PlayerLeft' => '$refresh', 'PlayerReadyChanged' => '$refresh'] as $event => $handler) {
            expect($listeners)->toHaveKey("echo-presence:room.K7MP9Z,{$event}", $handler);
        }
    });

    it('turns Echo on for the page and carries the CSRF token its channel auth needs', function () {
        $room = Room::factory()->create();

        $html = $this->actingAs($room->host)->get(route('host.lobby', $room->code))->getContent();

        expect($html)->toMatch('/<html[^>]* data-realtime/')->toContain('name="csrf-token"')->toContain('data-test="reconnecting"');
    });

    it('does not turn Echo on for the other pages', function () {
        expect($this->get('/')->getContent())->not->toContain('data-realtime');
        expect($this->actingAs(User::factory()->create())->get(route('rooms.create'))->getContent())->not->toContain('data-realtime');
        expect($this->get(route('join'))->getContent())->not->toContain('data-realtime');
    });

    it('polls to drop players who stay disconnected', function () {
        liveLobby(Room::factory()->create())->assertSeeHtml('wire:poll.5s="pruneDisconnected"');
    });
});

describe('presence', function () {
    it('here: marks the players on the channel connected and everyone else disconnected', function () {
        $room = Room::factory()->create();
        $on = RoomPlayer::factory()->for($room)->create(['left_at' => now()->subSeconds(5)]);
        $off = RoomPlayer::factory()->for($room)->create(['left_at' => null]);

        liveLobby($room)->call('presenceHere', [
            ['room_player_id' => null, 'is_host' => true, 'nickname' => 'Host'],
            ['room_player_id' => $on->id, 'is_host' => false, 'nickname' => 'Sara'],
        ]);

        expect($on->fresh()->left_at)->toBeNull()->and($off->fresh()->left_at)->not->toBeNull();
    });

    it('joining clears left_at', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['left_at' => now()->subSeconds(5)]);

        liveLobby($room)->call('presenceJoining', ['room_player_id' => $player->id, 'nickname' => 'Sara']);

        expect($player->fresh()->left_at)->toBeNull();
    });

    it('leaving sets left_at', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['left_at' => null]);

        liveLobby($room)->call('presenceLeaving', ['room_player_id' => $player->id]);

        expect($player->fresh()->left_at)->not->toBeNull();
    });

    it('ignores the host\'s own join and leave, and junk', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['left_at' => null]);

        liveLobby($room)
            ->call('presenceLeaving', ['room_player_id' => null, 'is_host' => true])
            ->call('presenceLeaving', [])
            ->call('presenceLeaving', ['room_player_id' => 'abc'])
            ->call('presenceJoining', ['room_player_id' => 'x']);

        expect($player->fresh()->left_at)->toBeNull();
    });

    it('is the host\'s job: another user\'s call is refused', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['left_at' => null]);

        Livewire::actingAs(User::factory()->create())->test(HostLobby::class, ['room' => $room])
            ->call('presenceLeaving', ['room_player_id' => $player->id])->assertForbidden();

        expect($player->fresh()->left_at)->toBeNull();
    });

    it('greys out disconnected players, shows Disconnected, and leaves them out of the counts', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Here', 'is_ready' => true, 'left_at' => null, 'joined_at' => now()->subMinutes(2)]);
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Gone', 'is_ready' => true, 'left_at' => now()->subSeconds(10), 'joined_at' => now()->subMinute()]);

        $page = liveLobby($room);

        $page->assertSeeInOrder(['Players', '1', '1 of 1 ready', 'Here', 'Ready', 'Gone', 'Disconnected'])->assertSeeHtml('data-disconnected');
        expect(substr_count($page->html(), 'data-disconnected'))->toBe(1)->and($page->html())->toContain('opacity-45');
    });

    it('drops players disconnected for over 30 seconds and keeps the others', function () {
        $room = Room::factory()->create();
        $gone = RoomPlayer::factory()->for($room)->create(['nickname' => 'Gone', 'left_at' => now()->subSeconds(40)]);
        $brief = RoomPlayer::factory()->for($room)->create(['nickname' => 'Brief', 'left_at' => now()->subSeconds(10)]);

        liveLobby($room)->call('pruneDisconnected')->assertDontSee('Gone')->assertSee('Brief');

        expect(RoomPlayer::find($gone->id))->toBeNull()->and(RoomPlayer::find($brief->id))->not->toBeNull();
    });

    it('does not prune for a non-host', function () {
        $room = Room::factory()->create();
        $gone = RoomPlayer::factory()->for($room)->create(['left_at' => now()->subSeconds(40)]);

        Livewire::actingAs(User::factory()->create())->test(HostLobby::class, ['room' => $room])->call('pruneDisconnected')->assertForbidden();

        expect(RoomPlayer::find($gone->id))->not->toBeNull();
    });

    it('lists the new row after a PlayerJoined refresh, in the waiting list', function () {
        $room = Room::factory()->create();
        $page = liveLobby($room)->assertDontSee('Late Sara');

        RoomPlayer::factory()->for($room)->create(['nickname' => 'Late Sara', 'left_at' => now()]);

        $page->dispatch("echo-presence:room.{$room->code},PlayerJoined")->assertSee('Late Sara')->assertSee('Disconnected');
    });
});

describe('removing a player', function () {
    it('lets the host remove a player with a button on the row', function () {
        Event::fake([PlayerLeft::class]);
        $room = Room::factory()->create();
        $kick = RoomPlayer::factory()->for($room)->create(['nickname' => 'Kick']);
        $stay = RoomPlayer::factory()->for($room)->create(['nickname' => 'Stay']);

        liveLobby($room)
            ->assertSeeHtml('aria-label="Remove Kick"')
            ->call('removePlayer', $kick->id)
            ->assertDontSee('Kick')->assertSee('Stay');

        expect(RoomPlayer::find($kick->id))->toBeNull()->and(RoomPlayer::find($stay->id))->not->toBeNull();
        Event::assertDispatched(PlayerLeft::class, fn (PlayerLeft $e) => $e->broadcastWith()['player']['id'] === $kick->id);
    });

    it('is the host\'s alone', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create();

        Livewire::actingAs(User::factory()->create())->test(HostLobby::class, ['room' => $room])->call('removePlayer', $player->id)->assertForbidden();

        expect(RoomPlayer::find($player->id))->not->toBeNull();
    });

    it('cannot reach a player of another room', function () {
        $room = Room::factory()->create();
        $stranger = RoomPlayer::factory()->for(Room::factory()->create())->create();

        liveLobby($room)->call('removePlayer', $stranger->id);

        expect(RoomPlayer::find($stranger->id))->not->toBeNull();
    });
});

describe('closing the room', function () {
    it('broadcasts RoomClosed to the phones and sends the host to room creation', function () {
        Event::fake([RoomClosed::class]);
        $room = Room::factory()->create();

        liveLobby($room)->call('closeRoom')->assertRedirect(route('rooms.create'));

        Event::assertDispatched(RoomClosed::class, fn (RoomClosed $e) => $e->roomCode === $room->code);
        expect($room->fresh()->status)->toBe(RoomStatus::Finished);
    });
});

describe('start button', function () {
    it('is disabled with nobody in the room', function () {
        expect(startButton(liveLobby(Room::factory()->create())))->toBe('disabled');
    });

    it('is disabled while a connected player is not ready', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['is_ready' => true, 'left_at' => null]);
        RoomPlayer::factory()->for($room)->create(['is_ready' => false, 'left_at' => null]);

        expect(startButton(liveLobby($room)))->toBe('disabled');
    });

    it('is disabled with only disconnected players', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['is_ready' => true, 'left_at' => now()->subSeconds(5)]);

        expect(startButton(liveLobby($room)))->toBe('disabled');
    });

    it('is enabled with one connected player who is ready', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['is_ready' => true, 'left_at' => null]);

        expect(startButton(liveLobby($room)))->toBe('enabled');
    });

    it('is enabled when every connected player is ready, whatever the disconnected ones are doing', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->count(2)->create(['is_ready' => true, 'left_at' => null]);
        RoomPlayer::factory()->for($room)->create(['is_ready' => false, 'left_at' => now()->subSeconds(5)]);

        expect(startButton(liveLobby($room)))->toBe('enabled');
    });

    it('starts the game: playing, "Get ready…" on the host screen, GameStarted broadcast', function () {
        Event::fake([GameStarted::class]);
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['is_ready' => true, 'left_at' => null]);

        liveLobby($room)->call('start')->assertSee('Get ready…')->assertSeeHtml('data-test="get-ready"')->assertDontSeeHtml('data-test="player-list"');

        expect($room->fresh()->status)->toBe(RoomStatus::Playing);
        Event::assertDispatchedTimes(GameStarted::class, 1);
    });

    it('says why when it cannot start', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['is_ready' => false, 'left_at' => null]);

        liveLobby($room)->call('start')->assertSee('Everyone needs to tap Ready first.')->assertSeeHtml('data-test="start-error"');

        expect($room->fresh()->status)->toBe(RoomStatus::Lobby);
    });

    it('starts once however many times it is clicked', function () {
        Event::fake([GameStarted::class]);
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['is_ready' => true, 'left_at' => null]);

        liveLobby($room)->call('start')->call('start');

        Event::assertDispatchedTimes(GameStarted::class, 1);
    });

    it('is the host\'s alone', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['is_ready' => true, 'left_at' => null]);

        Livewire::actingAs(User::factory()->create())->test(HostLobby::class, ['room' => $room])->call('start')->assertForbidden();

        expect($room->fresh()->status)->toBe(RoomStatus::Lobby);
    });

    it('shows the Arabic line too on "Get ready…" when the screen language is Both', function () {
        $room = Room::factory()->create(['settings' => new RoomSettings(hostScreenLocale: HostScreenLocale::Both)]);
        RoomPlayer::factory()->for($room)->create(['is_ready' => true, 'left_at' => null]);

        liveLobby($room)->call('start')->assertSee('استعدوا…')->assertSeeHtml('lang="ar" dir="rtl"');
    });

    it('clears an old refusal once the room becomes startable', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['is_ready' => false, 'left_at' => null]);

        $page = liveLobby($room)->call('start')->assertSee('Everyone needs to tap Ready first.');
        $player->update(['is_ready' => true]);

        $page->call('$refresh')->assertDontSee('Everyone needs to tap Ready first.');
    });
});
