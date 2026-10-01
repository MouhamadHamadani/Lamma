<?php

use App\Enums\RoomStatus;
use App\Events\PlayerLeft;
use App\Events\PlayerReadyChanged;
use App\Livewire\Player\PlayerLobby;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const LIVE_TOKEN = 'l1v2e3l1v2e3l1v2e3l1v2e3l1v2e3l1v2e3l1v2e3l1v2e3l1v2e3l1v2e3l1v2';

/** @return array{0: Room, 1: RoomPlayer, 2: Testable} */
function livePhone(array $me = [], ?Room $room = null): array
{
    $room ??= Room::factory()->create();
    $player = RoomPlayer::factory()->for($room)->create(['guest_token' => LIVE_TOKEN, 'nickname' => 'Sara', 'locale' => 'en', 'left_at' => null, ...$me]);

    return [$room, $player, Livewire::withCookie('lamma_guest', LIVE_TOKEN)->test(PlayerLobby::class, ['room' => $room])];
}

describe('real-time wiring', function () {
    it('listens on the room presence channel for presence changes and every lobby event', function () {
        [$room, , $page] = livePhone();
        $code = $room->code;

        $listeners = $page->instance()->getListeners();

        foreach (['here', 'joining', 'leaving', 'PlayerJoined', 'PlayerLeft', 'PlayerReadyChanged', 'GameStarted'] as $event) {
            expect($listeners)->toHaveKey("echo-presence:room.{$code},{$event}", '$refresh');
        }
        expect($listeners)->toHaveKey("echo-presence:room.{$code},RoomClosed", 'roomClosed');
    });

    it('turns Echo on for the page', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => LIVE_TOKEN]);

        $html = $this->withCookie('lamma_guest', LIVE_TOKEN)->get(route('play', $room->code))->getContent();

        expect($html)->toMatch('/<html[^>]* data-realtime/')->toContain('data-test="reconnecting"');
    });

    it('refreshes the list when another player joins, readies up or leaves', function () {
        [$room, , $page] = livePhone();
        $other = RoomPlayer::factory()->for($room)->create(['nickname' => 'Ali', 'is_ready' => false, 'left_at' => null, 'joined_at' => now()->addSecond()]);
        $page->assertDontSee('Ali');

        $page->dispatch("echo-presence:room.{$room->code},PlayerJoined")->assertSee('Ali')->assertSee('0 of 2 ready');

        $other->update(['is_ready' => true]);
        $page->dispatch("echo-presence:room.{$room->code},PlayerReadyChanged")->assertSee('1 of 2 ready');

        $other->delete();
        $page->dispatch("echo-presence:room.{$room->code},PlayerLeft")->assertDontSee('Ali')->assertSee('0 of 1 ready');
    });

    it('greys out disconnected players and leaves them out of the ready count', function () {
        [$room, , $page] = livePhone(['is_ready' => true]);
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Gone', 'is_ready' => false, 'left_at' => now()->subSeconds(5), 'joined_at' => now()->addSecond()]);

        $page->dispatch("echo-presence:room.{$room->code},here")->assertSee('Gone')->assertSee('Disconnected')->assertSee('1 of 1 ready');
    });
});

describe('the Ready toggle', function () {
    it('broadcasts PlayerReadyChanged with the new value', function () {
        Event::fake([PlayerReadyChanged::class]);
        [, $player, $page] = livePhone();

        $page->call('toggleReady')->call('toggleReady');

        $values = Event::dispatched(PlayerReadyChanged::class)->map(fn ($call) => $call[0]->broadcastWith()['player']['is_ready'])->all();
        expect($values)->toBe([true, false]);
        Event::assertDispatched(PlayerReadyChanged::class, fn (PlayerReadyChanged $e) => $e->broadcastWith()['player']['id'] === $player->id);
    });
});

describe('leaving', function () {
    it('removes the player\'s row, tells the others, and goes home', function () {
        Event::fake([PlayerLeft::class]);
        [$room, $player, $page] = livePhone();
        $stay = RoomPlayer::factory()->for($room)->create();

        $page->call('leave')->assertRedirect(route('home'));

        expect(RoomPlayer::find($player->id))->toBeNull()->and(RoomPlayer::find($stay->id))->not->toBeNull();
        Event::assertDispatched(PlayerLeft::class, fn (PlayerLeft $e) => $e->broadcastWith()['player']['id'] === $player->id);
    });

    it('shows a Leave button in the lobby', function () {
        [, , $page] = livePhone();

        $page->assertSeeHtml('data-test="leave-button"')->assertSee('Leave room');
    });

    it('lets the nickname be taken again afterwards', function () {
        [$room, , $page] = livePhone(['nickname' => 'Sara']);

        $page->call('leave');

        expect(RoomPlayer::factory()->for($room)->create(['nickname' => 'Sara'])->exists)->toBeTrue();
    });

    it('does not remove a player from a game that has started', function () {
        [, $player, $page] = livePhone(room: Room::factory()->playing()->create());

        $page->call('leave');

        expect(RoomPlayer::find($player->id))->not->toBeNull();
    });
});

describe('the room closing', function () {
    it('shows a message and goes home when RoomClosed arrives', function () {
        [$room, , $page] = livePhone();

        $page->dispatch("echo-presence:room.{$room->code},RoomClosed")->assertRedirect(route('home'));

        expect(session('notice'))->toBe('The host closed this room.');
    });

    it('shows the message on the page it goes to', function () {
        $this->withSession(['notice' => 'The host closed this room.'])->get('/')->assertSee('The host closed this room.')->assertSee('data-test="notice"', false);
        $this->withSession(['notice' => "You're not in this room. Join again with the code."])->get(route('join'))->assertSee('not in this room');
        $this->flushSession();
        $this->get('/')->assertDontSee('data-test="notice"', false);
    });

    it('says so on a phone that opens a room that was closed meanwhile', function () {
        [, , $page] = livePhone(room: Room::factory()->finished()->create());

        $page->assertSee('This room has been closed.')->assertSeeHtml('data-test="closed"')->assertDontSeeHtml('data-test="ready-button"');
    });

    it('is in the player\'s own language', function () {
        [$room, , $page] = livePhone(['locale' => 'ar']);

        $page->dispatch("echo-presence:room.{$room->code},RoomClosed");

        expect(session('notice'))->toBe('أغلق المضيف هذه الغرفة.');
    });
});

describe('being removed', function () {
    it('sends a phone whose row is gone to the join screen with a message', function () {
        [$room, $player, $page] = livePhone();
        $player->delete(); // the host removed them

        $page->dispatch("echo-presence:room.{$room->code},PlayerLeft")->assertRedirect(route('join', ['code' => $room->code]));

        expect(session('notice'))->toBe("You're not in this room. Join again with the code.");
    });

    it('does the same for a player dropped after being offline, whatever refreshes the page', function () {
        [$room, $player, $page] = livePhone();
        $player->delete();

        $page->call('$refresh')->assertRedirect(route('join', ['code' => $room->code]));
    });

    it('does not touch the database for a phone that is no longer in the room', function () {
        [$room, $player, $page] = livePhone();
        $other = RoomPlayer::factory()->for($room)->create(['is_ready' => false]);
        $player->delete();

        $page->call('toggleReady');

        expect($other->fresh()->is_ready)->toBeFalse();
    });
});

describe('the game starting', function () {
    it('moves the phone to "Get ready…" when GameStarted arrives', function () {
        [$room, , $page] = livePhone();
        $page->assertSeeHtml('data-test="ready-button"');

        $room->update(['status' => RoomStatus::Playing]);
        $page->dispatch("echo-presence:room.{$room->code},GameStarted")->assertSee('Get ready…')->assertSeeHtml('data-test="get-ready"')
            ->assertDontSeeHtml('data-test="ready-button"')->assertDontSeeHtml('data-test="leave-button"');
    });

    it('says it in the player\'s language', function () {
        [, , $page] = livePhone(['locale' => 'ar'], Room::factory()->playing()->create());

        $page->assertSee('استعدوا…')->assertSee('اللعبة على وشك أن تبدأ.');
    });
});
