<?php

use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Game\RoomSettings;
use App\Livewire\Host\HostLobby;
use App\Models\Category;
use App\Models\Room;
use App\Models\RoomPlayer;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function lobbyFor(Room $room): Testable
{
    return Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room]);
}

function roomWith(array $settings = []): Room
{
    return Room::factory()->create(['settings' => new RoomSettings(...$settings)]);
}

describe('join instructions', function () {
    it('shows the room code as four tilted sticker tiles, left-to-right', function () {
        $room = Room::factory()->create(['code' => 'K7MP9Z']);

        $html = test()->actingAs($room->host)->get(route('host.lobby', $room->code))->getContent();

        expect($html)->toMatch('/dir="ltr"[^>]*aria-label="K 7 M P 9 Z"/')->toContain('data-test="room-code"')
            ->toContain('shadow-sticker-lg')->toContain('-rotate-3');
    });

    it('shows the join address without the scheme, left-to-right, and the instructions', function () {
        $room = Room::factory()->create();

        $this->actingAs($room->host)->withSession(['locale' => 'en'])->get(route('host.lobby', $room->code))
            ->assertSee('Join on your phone')->assertSee('Go to')->assertSee('and enter this room code:')
            ->assertSee('data-test="join-address"', false)
            ->assertSee('localhost:8000/join')
            ->assertSee('Each player picks Arabic or English when they join.');
    });

    it('shows a QR code that points at /join?code=XXXX', function () {
        $room = Room::factory()->create(['code' => 'K7MP9Z']);

        $this->actingAs($room->host)->get(route('host.lobby', $room->code))
            ->assertSee('data-test="qr-code"', false)
            ->assertSee('data-url="'.url('/join?code=K7MP9Z').'"', false)
            ->assertSee('fill="currentColor"', false)
            ->assertSee('role="img"', false);
    });

    it('also shows the Arabic instruction when the screen language is Both, marked lang="ar" dir="rtl"', function () {
        $both = roomWith(['hostScreenLocale' => HostScreenLocale::Both]);
        $en = roomWith(['hostScreenLocale' => HostScreenLocale::En]);

        $this->actingAs($both->host)->get(route('host.lobby', $both->code))->assertSee('lang="ar" dir="rtl"', false)->assertSee('وأدخل رمز الغرفة هذا:');
        $this->actingAs($en->host)->get(route('host.lobby', $en->code))->assertDontSee('وأدخل رمز الغرفة هذا:');
    });

    it('is entirely in Arabic, right-to-left, for an Arabic room', function () {
        $room = roomWith(['hostScreenLocale' => HostScreenLocale::Ar]);

        $this->actingAs($room->host)->get(route('host.lobby', $room->code))
            ->assertSee('dir="rtl"', false)->assertSee('انضم من هاتفك')->assertSee('ابدأ اللعبة')->assertSee('اللاعبون')
            ->assertDontSee('Join on your phone');
    });
});

describe('header', function () {
    it('shows the categories, game length and the Close room link', function () {
        $geo = Category::factory()->create(['name' => ['en' => 'Geography', 'ar' => 'جغرافيا']]);
        $sci = Category::factory()->create(['name' => ['en' => 'Science', 'ar' => 'علوم']]);
        $room = roomWith(['categoryIds' => [$geo->id, $sci->id], 'questionCount' => 15, 'secondsPerQuestion' => 30, 'hostScreenLocale' => HostScreenLocale::En]);

        lobbyFor($room)->assertSee('Lobby')->assertSee('Geography · Science')->assertSee('15 questions · 30 s')->assertSee('Close room');
    });

    it('closes the room and returns the host to room creation', function () {
        $room = Room::factory()->create();

        lobbyFor($room)->call('closeRoom')->assertRedirect(route('rooms.create'));

        expect($room->fresh()->status)->toBe(RoomStatus::Finished)->and($room->fresh()->finished_at)->not->toBeNull();
    });

    it('asks for confirmation before closing', function () {
        lobbyFor(Room::factory()->create())->assertSeeHtml('wire:confirm=');
    });
});

describe('player list', function () {
    it('shows an empty room with only the waiting row, and a count of zero', function () {
        lobbyFor(Room::factory()->create())
            ->assertSee('Waiting for more players…')->assertSeeHtml('data-test="player-count"')
            ->assertSeeInOrder(['Players', '0', '0 of 0 ready']);
    });

    it('lists the players from the database in join order with language and status', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Sara', 'locale' => 'en', 'is_ready' => true, 'joined_at' => now()->subMinutes(3)]);
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Ali', 'locale' => 'ar', 'is_ready' => true, 'joined_at' => now()->subMinutes(2)]);
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Maya', 'locale' => 'en', 'is_ready' => false, 'joined_at' => now()->subMinute()]);
        RoomPlayer::factory()->create(['nickname' => 'Elsewhere']); // another room

        lobbyFor($room)
            ->assertSeeInOrder(['Players', '3', '2 of 3 ready', 'Sara', 'EN', 'Ready', 'Ali', 'ع', 'Ready', 'Maya', 'EN', 'Not ready', 'Waiting for more players…'])
            ->assertDontSee('Elsewhere');
    });

    it('keeps the waiting row at the end of the list', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->count(3)->create();

        $html = lobbyFor($room)->html();

        expect(substr_count($html, 'Waiting for more players…'))->toBe(1)
            ->and(strrpos($html, 'Waiting for more players…'))->toBeGreaterThan(strrpos($html, '<li'));
        expect(substr_count($html, '<li'))->toBe(4);
    });

    it('shows nicknames left-to-right even in an Arabic room', function () {
        $room = roomWith(['hostScreenLocale' => HostScreenLocale::Ar]);
        RoomPlayer::factory()->for($room)->create(['nickname' => 'سارة', 'locale' => 'ar']);

        lobbyFor($room)->assertSeeHtml('<bdi dir="ltr">سارة</bdi>');
    });

    it('escapes nicknames', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['nickname' => '<script>alert(1)</script>']);

        lobbyFor($room)->assertDontSeeHtml('<script>alert(1)</script>')->assertSee('&lt;script&gt;', false);
    });

    it('announces the ready count politely', function () {
        lobbyFor(Room::factory()->create())->assertSeeHtml('aria-live="polite"');
    });
});

describe('start button', function () {
    it('is disabled with its reason shown, even when everyone is ready', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->count(2)->create(['is_ready' => true]);

        lobbyFor($room)
            ->assertSeeHtml('data-test="start-button"')
            ->assertSee('Unlocks when everyone taps Ready.')
            ->assertSee('2 of 2 ready');

        expect(lobbyFor($room)->html())->toMatch('/<button[^>]*disabled[^>]*data-test="start-button"|<button[^>]*data-test="start-button"[^>]*disabled/s');
    });
});

it('keeps the start button disabled when nobody is in the room', function () {
    expect(lobbyFor(Room::factory()->create())->html())->toMatch('/<button[^>]*\sdisabled[\s>][^>]*data-test="start-button"|<button[^>]*data-test="start-button"[^>]*\sdisabled[\s>]/s');
});
