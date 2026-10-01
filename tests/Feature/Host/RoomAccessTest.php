<?php

use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Game\RoomSettings;
use App\Livewire\Host\HostLobby;
use App\Livewire\Player\PlayerLobby;
use App\Models\Admin;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

const TOKEN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

describe('/host/{code}: only the room host', function () {
    it('lets the host in', function () {
        $room = Room::factory()->create();

        $this->actingAs($room->host)->get(route('host.lobby', $room->code))->assertOk()->assertSeeLivewire(HostLobby::class);
    });

    it('sends guests to log in', function () {
        $room = Room::factory()->create();

        $this->get(route('host.lobby', $room->code))->assertRedirect(route('login'));
    });

    it('forbids every other logged-in user, even a player in that room', function () {
        $room = Room::factory()->create();
        $player = User::factory()->create();
        RoomPlayer::factory()->for($room)->forUser($player)->create();

        $this->actingAs($player)->get(route('host.lobby', $room->code))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('host.lobby', $room->code))->assertForbidden();
    });

    it('does not treat an admin-guard login as a host', function () {
        $room = Room::factory()->create();

        $this->actingAs(Admin::factory()->create(), 'admin');
        Auth::shouldUse('web');

        $this->get(route('host.lobby', $room->code))->assertRedirect(route('login'));
    });

    it('finds the room whatever the letter case, and 404s an unknown code', function () {
        $room = Room::factory()->create();

        $this->actingAs($room->host)->get('/host/'.strtolower($room->code))->assertOk();
        $this->actingAs($room->host)->get('/host/ZZZZZZ')->assertNotFound();
    });

    it('sends the host away from a room they have closed', function () {
        $room = Room::factory()->finished()->create();

        $this->actingAs($room->host)->get(route('host.lobby', $room->code))->assertRedirect(route('rooms.create'));
    });

    it('checks again on every action, not just on page load', function () {
        $room = Room::factory()->create();

        Livewire::actingAs(User::factory()->create())->test(HostLobby::class, ['room' => $room])->call('closeRoom')->assertForbidden();

        expect($room->fresh()->status)->toBe(RoomStatus::Lobby);
    });
});

describe('/play/{code}: only the room participants', function () {
    it('lets a guest in by their token', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => TOKEN]);

        $this->withCookie('lamma_guest', TOKEN)->get(route('play', $room->code))->assertOk()->assertSeeLivewire(PlayerLobby::class);
    });

    it('lets a logged-in participant in', function () {
        $room = Room::factory()->create();
        $user = User::factory()->create();
        RoomPlayer::factory()->for($room)->forUser($user)->create();

        $this->actingAs($user)->get(route('play', $room->code))->assertOk();
    });

    it('sends a stranger to the join screen with the code filled in', function () {
        $room = Room::factory()->create();

        $this->get(route('play', $room->code))->assertRedirect(route('join', ['code' => $room->code]));
        $this->actingAs(User::factory()->create())->get(route('play', $room->code))->assertRedirect(route('join', ['code' => $room->code]));
    });

    it('sends someone who is only in a different room', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for(Room::factory()->create())->create(['guest_token' => TOKEN]);

        $this->withCookie('lamma_guest', TOKEN)->get(route('play', $room->code))->assertRedirect(route('join', ['code' => $room->code]));
    });

    it('does not let the host in through the phone screen', function () {
        $room = Room::factory()->create();

        $this->actingAs($room->host)->get(route('play', $room->code))->assertRedirect(route('join', ['code' => $room->code]));
    });

    it('does not accept a made-up or malformed guest cookie', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => TOKEN]);

        $this->withCookie('lamma_guest', str_repeat('z', 64))->get(route('play', $room->code))->assertRedirect();
        $this->withCookie('lamma_guest', 'x')->get(route('play', $room->code))->assertRedirect();
    });

    it('keeps a row claimed by one account away from the next person to log in on that device', function () {
        $room = Room::factory()->create();
        $owner = User::factory()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => TOKEN, 'user_id' => $owner->id]);

        $this->actingAs(User::factory()->create())->withCookie('lamma_guest', TOKEN)->get(route('play', $room->code))->assertRedirect();
        $this->actingAs($owner)->withCookie('lamma_guest', TOKEN)->get(route('play', $room->code))->assertOk();
    });

    it('lets a user who logged in mid-lobby keep their unclaimed guest row', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => TOKEN]);

        $this->actingAs(User::factory()->create())->withCookie('lamma_guest', TOKEN)->get(route('play', $room->code))->assertOk();
    });

    it('404s an unknown code', function () {
        $this->get('/play/ZZZZZZ')->assertNotFound();
    });

    it('stays open to participants after the game has started or finished', function (string $state) {
        $room = Room::factory()->{$state}()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => TOKEN]);

        $this->withCookie('lamma_guest', TOKEN)->get(route('play', $room->code))->assertOk();
    })->with(['playing', 'finished']);

    it('checks the participant again on every action', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['guest_token' => TOKEN]);

        $page = Livewire::withCookie('lamma_guest', TOKEN)->test(PlayerLobby::class, ['room' => $room]);
        $player->delete(); // no longer a participant by the time the action arrives

        $page->call('toggleReady')->assertForbidden();
    });
});

describe('language of each screen', function () {
    it('shows a phone in the player own language, not the site language', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => TOKEN, 'locale' => 'ar']);

        $this->withSession(['locale' => 'en'])->withCookie('lamma_guest', TOKEN)->get(route('play', $room->code))
            ->assertSee('<html lang="ar" dir="rtl"', false);

        RoomPlayer::query()->update(['locale' => 'en']);
        $this->withSession(['locale' => 'ar'])->withCookie('lamma_guest', TOKEN)->get(route('play', $room->code))
            ->assertSee('<html lang="en" dir="ltr"', false);
    });

    it('shows the host screen in the room language; Both is left-to-right with Arabic lines marked', function (HostScreenLocale $setting, string $lang, string $dir) {
        $room = Room::factory()->create(['settings' => new RoomSettings(hostScreenLocale: $setting)]);

        $this->actingAs($room->host)->withSession(['locale' => $lang === 'ar' ? 'en' : 'ar'])->get(route('host.lobby', $room->code))
            ->assertSee("<html lang=\"{$lang}\" dir=\"{$dir}\"", false);
    })->with([
        'arabic' => [HostScreenLocale::Ar, 'ar', 'rtl'],
        'english' => [HostScreenLocale::En, 'en', 'ltr'],
        'both' => [HostScreenLocale::Both, 'en', 'ltr'],
    ]);
});

describe('admin', function () {
    it('can still see every room in Filament', function () {
        $room = Room::factory()->create();

        $this->actingAs(Admin::factory()->create(), 'admin')->get('/admin/rooms')->assertOk()->assertSee($room->code);
        $this->actingAs(Admin::factory()->create(), 'admin')->get("/admin/rooms/{$room->id}")->assertOk();
    });
});
