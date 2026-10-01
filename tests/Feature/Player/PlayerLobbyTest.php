<?php

use App\Enums\RoomStatus;
use App\Livewire\Player\PlayerLobby;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const PHONE_TOKEN = 'p1h2o3n4e5p1h2o3n4e5p1h2o3n4e5p1h2o3n4e5p1h2o3n4e5p1h2o3n4e5p1h2';

/** @return array{0: Room, 1: RoomPlayer, 2: Testable} */
function phoneLobby(array $me = [], ?Room $room = null): array
{
    $room ??= Room::factory()->create();
    $player = RoomPlayer::factory()->for($room)->create(['guest_token' => PHONE_TOKEN, 'nickname' => 'Sara', 'locale' => 'en', 'joined_at' => now()->subMinutes(5), ...$me]);

    return [$room, $player, Livewire::withCookie('lamma_guest', PHONE_TOKEN)->test(PlayerLobby::class, ['room' => $room])];
}

describe('the screen', function () {
    it('welcomes the player by nickname and shows the room code', function () {
        [$room, , $page] = phoneLobby();

        $page->assertSee("You're in,", false)->assertSeeHtml('<bdi dir="ltr">Sara</bdi>!')->assertSee('Waiting for the host to start the game.')
            ->assertSeeHtml('data-test="room-code"')->assertSee($room->code);
    });

    it('lists everyone, marks the current player with (you) and counts who is ready', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Ali', 'is_ready' => true, 'joined_at' => now()->subMinutes(4)]);
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Maya', 'is_ready' => false, 'joined_at' => now()->subMinutes(3)]);
        [, , $page] = phoneLobby(['is_ready' => true, 'joined_at' => now()->subMinutes(5)], $room);

        $page->assertSeeInOrder(['Players', '2 of 3 ready', 'Sara', '(you)', 'Ready', 'Ali', 'Ready', 'Maya', 'Not ready']);
        expect(substr_count($page->html(), '(you)'))->toBe(1);
    });

    it('does not show other rooms\' players', function () {
        [, , $page] = phoneLobby();
        RoomPlayer::factory()->create(['nickname' => 'Elsewhere']);

        $page->assertDontSee('Elsewhere');
    });

    it('works for a logged-in participant', function () {
        $room = Room::factory()->create();
        $user = User::factory()->create();
        RoomPlayer::factory()->for($room)->forUser($user)->create(['nickname' => 'Layla', 'locale' => 'en']);

        Livewire::actingAs($user)->test(PlayerLobby::class, ['room' => $room])->assertSee('Layla')->assertSee('(you)');
    });

    it('is in the player\'s own language, with the nickname left-to-right', function () {
        [, , $page] = phoneLobby(['locale' => 'ar', 'nickname' => 'سارة']);

        $page->assertSee('أهلاً بك')->assertSee('بانتظار المضيف ليبدأ اللعبة.')->assertSee('أنا جاهز')
            ->assertSeeHtml('<bdi dir="ltr">سارة</bdi>')->assertDontSee('Waiting for the host');
    });

    it('puts the nickname in the greeting in both languages, escaped', function () {
        [, , $page] = phoneLobby(['nickname' => '<b>Sara</b>']);
        $page->assertSeeHtml('<bdi dir="ltr">&lt;b&gt;Sara&lt;/b&gt;</bdi>!')->assertDontSeeHtml('<b>Sara</b>');

        [, , $arabic] = phoneLobby(['nickname' => 'سارة', 'locale' => 'ar']);
        $arabic->assertSeeHtml('أهلاً بك، <bdi dir="ltr">سارة</bdi>!');
    });

    it('does not poll: it is refreshed by the room\'s events', function () {
        [, , $page] = phoneLobby();

        expect($page->html())->not->toContain('wire:poll');
    });
});

describe('the Ready toggle', function () {
    it('starts not ready: a coral "I\'m ready" button', function () {
        [, , $page] = phoneLobby();

        $page->assertSeeHtml('data-test="ready-button"')->assertSee("I'm ready")->assertSeeHtml('aria-pressed="false"')
            ->assertSee("Tap when you're ready to play.")->assertDontSee("I'm ready!");
        expect($page->html())->toContain('bg-coral');
    });

    it('saves is_ready to the database when tapped and turns teal with a check', function () {
        [, $player, $page] = phoneLobby();

        $page->call('toggleReady')->assertSee("I'm ready!")->assertSeeHtml('aria-pressed="true"')->assertSee('Tap again if you need a minute.');

        expect($player->fresh()->is_ready)->toBeTrue()
            ->and($page->html())->toContain('bg-teal');
    });

    it('toggles back off', function () {
        [, $player, $page] = phoneLobby();

        $page->call('toggleReady')->call('toggleReady')->assertSee("Tap when you're ready to play.");

        expect($player->fresh()->is_ready)->toBeFalse();
    });

    it('changes only the tapping player\'s own row', function () {
        [$room, $player, $page] = phoneLobby();
        $other = RoomPlayer::factory()->for($room)->create(['is_ready' => false]);

        $page->call('toggleReady');

        expect($player->fresh()->is_ready)->toBeTrue()->and($other->fresh()->is_ready)->toBeFalse();
    });

    it('counts the player in the ready total straight away', function () {
        [, , $page] = phoneLobby();

        $page->assertSee('0 of 1 ready')->call('toggleReady')->assertSee('1 of 1 ready');
    });

    it('does nothing once the game has started', function () {
        [$room, $player, $page] = phoneLobby();
        $room->update(['status' => RoomStatus::Playing]);

        $page->call('toggleReady');

        expect($player->fresh()->is_ready)->toBeFalse();
    });

    it('hides the button and shows "Get ready…" once the game has started', function () {
        [, , $page] = phoneLobby(room: Room::factory()->playing()->create());

        $page->assertSee('Get ready…')->assertSeeHtml('data-test="get-ready"')->assertDontSeeHtml('data-test="ready-button"');
    });
});
