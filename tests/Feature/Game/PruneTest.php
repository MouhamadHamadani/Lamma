<?php

use App\Enums\RoomStatus;
use App\Events\RoomClosed;
use App\Game\Housekeeping;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([RoomClosed::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
});

describe('abandoned lobbies', function () {
    it('finishes lobbies older than six hours and tells the phones in them', function () {
        $abandoned = Room::factory()->create(['created_at' => now()->subHours(6)->subSecond()]);

        $this->artisan('lamma:prune')->assertSuccessful();

        expect($abandoned->fresh()->status)->toBe(RoomStatus::Finished)->and($abandoned->fresh()->finished_at)->not->toBeNull();
        Event::assertDispatched(RoomClosed::class, fn (RoomClosed $e) => $e->roomCode === $abandoned->code);
    });

    it('leaves a lobby that is still within six hours', function () {
        $recent = Room::factory()->create(['created_at' => now()->subHours(6)]);
        $fresh = Room::factory()->create(['created_at' => now()->subMinutes(5)]);

        $this->artisan('lamma:prune')->assertSuccessful();

        expect($recent->fresh()->status)->toBe(RoomStatus::Lobby)->and($fresh->fresh()->status)->toBe(RoomStatus::Lobby);
        Event::assertNotDispatched(RoomClosed::class);
    });

    it('leaves games being played and rooms that are already finished', function () {
        $playing = Room::factory()->playing()->create(['created_at' => now()->subDay()]);
        $finished = Room::factory()->finished()->create(['created_at' => now()->subDay(), 'finished_at' => now()->subHours(20)]);

        $this->artisan('lamma:prune')->assertSuccessful();

        expect($playing->fresh()->status)->toBe(RoomStatus::Playing)
            ->and($finished->fresh()->status)->toBe(RoomStatus::Finished)->and($finished->fresh()->finished_at->toDateTimeString())->toBe('2026-10-14 16:00:00');
        Event::assertNotDispatched(RoomClosed::class);
    });

    it('does nothing the second time', function () {
        Room::factory()->create(['created_at' => now()->subDay()]);

        $this->artisan('lamma:prune')->assertSuccessful();
        $this->artisan('lamma:prune')->expectsOutputToContain('Closed 0 abandoned lobbies')->assertSuccessful();

        Event::assertDispatchedTimes(RoomClosed::class, 1);
    });
});

describe('guest tokens', function () {
    it('clears the token of unclaimed guest rows older than the claim window', function () {
        $hours = (int) config('lamma.guest_claim_hours');
        $room = Room::factory()->finished()->create();
        $old = RoomPlayer::factory()->for($room)->create(['created_at' => now()->subHours($hours)->subSecond()]);
        $edge = RoomPlayer::factory()->for($room)->create(['created_at' => now()->subHours($hours)]);
        $recent = RoomPlayer::factory()->for($room)->create(['created_at' => now()->subHour()]);

        $this->artisan('lamma:prune')->assertSuccessful();

        expect($old->fresh()->guest_token)->toBeNull()->and($edge->fresh()->guest_token)->not->toBeNull()->and($recent->fresh()->guest_token)->not->toBeNull();
    });

    it('keeps the row itself: only the token goes', function () {
        $old = RoomPlayer::factory()->create(['nickname' => 'Sara', 'score' => 700, 'created_at' => now()->subDays(3)]);

        $this->artisan('lamma:prune')->assertSuccessful();

        expect($old->fresh())->not->toBeNull()->and($old->fresh()->nickname)->toBe('Sara')->and($old->fresh()->score)->toBe(700)->and($old->fresh()->user_id)->toBeNull();
    });

    it('leaves rows that belong to an account alone', function () {
        $user = User::factory()->create();
        $mine = RoomPlayer::factory()->forUser($user)->create(['created_at' => now()->subDays(3)]);

        $this->artisan('lamma:prune')->assertSuccessful();

        expect($mine->fresh()->user_id)->toBe($user->id);
    });

    it('means a guest can no longer claim the game by logging in on the same device', function () {
        $token = str_repeat('c', 64);
        $room = Room::factory()->finished()->create();
        $row = RoomPlayer::factory()->for($room)->create(['guest_token' => $token, 'created_at' => now()->subHours(config('lamma.guest_claim_hours') + 2)]);
        $user = User::factory()->create();

        $this->artisan('lamma:prune')->assertSuccessful();
        $this->withCookie('lamma_guest', $token)->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        expect($row->fresh()->user_id)->toBeNull()->and($row->fresh()->guest_token)->toBeNull();
    });

    it('reports what it did', function () {
        Room::factory()->create(['created_at' => now()->subDay()]);
        RoomPlayer::factory()->create(['created_at' => now()->subDays(3)]);

        $this->artisan('lamma:prune')->expectsOutputToContain('Closed 1 abandoned lobbies, cleared 1 expired guest tokens.')->assertSuccessful();
    });
});

it('is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command, 'lamma:prune'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 0 * * *');
});

it('can be run from the Housekeeping service too', function () {
    Room::factory()->create(['created_at' => now()->subDay()]);

    expect(app(Housekeeping::class)->closeAbandonedLobbies())->toBe(1)->and(app(Housekeeping::class)->clearExpiredGuestTokens())->toBe(0);
});
