<?php

use App\Models\Admin;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;

const GUEST = 'g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1g1';
const OTHER_DEVICE = 'o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2o2';

function guestRow(string $token = GUEST, ?Room $room = null, array $attributes = []): RoomPlayer
{
    return RoomPlayer::factory()->for($room ?? Room::factory()->create())->create([
        'guest_token' => $token, 'user_id' => null, ...$attributes,
    ]);
}

function logInWithCookie(User $user, ?string $token = GUEST): void
{
    $request = test();
    if ($token) {
        $request = $request->withCookie('lamma_guest', $token);
    }
    $request->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();
}

describe('claiming on login', function () {
    it('attaches this device\'s guest results to the account that logs in', function () {
        $user = User::factory()->create();
        $row = guestRow(attributes: ['nickname' => 'Sara', 'score' => 300]);

        logInWithCookie($user);

        expect($row->fresh())->user_id->toBe($user->id)->nickname->toBe('Sara')->score->toBe(300);
    });

    it('claims every guest row from the device across rooms', function () {
        $user = User::factory()->create();
        $rows = [guestRow(), guestRow(), guestRow()];

        logInWithCookie($user);

        expect(RoomPlayer::whereIn('id', collect($rows)->pluck('id'))->pluck('user_id')->unique()->all())->toBe([$user->id]);
    });

    it('claims on registration too', function () {
        $row = guestRow();

        $this->withCookie('lamma_guest', GUEST)->post(route('register.store'), [
            'name' => 'New Player', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        expect($row->fresh()->user_id)->toBe(User::where('email', 'new@example.com')->value('id'));
    });

    it('also works when the events are dispatched directly', function () {
        $user = User::factory()->create();
        $row = guestRow();
        $this->withCookie('lamma_guest', GUEST);
        app()->instance('request', request()->duplicate(cookies: ['lamma_guest' => GUEST]));

        event(new Login('web', $user, false));

        expect($row->fresh()->user_id)->toBe($user->id);
    });
});

describe('what is not claimed', function () {
    it('does not claim rows older than the claim window', function () {
        $user = User::factory()->create();
        $old = guestRow(attributes: ['created_at' => now()->subHours(25)]);
        $fresh = guestRow(attributes: ['created_at' => now()->subHours(23)]);

        logInWithCookie($user);

        expect($old->fresh()->user_id)->toBeNull()
            ->and($fresh->fresh()->user_id)->toBe($user->id);
    });

    it('reads the window from config', function () {
        config(['lamma.guest_claim_hours' => 48]);
        $user = User::factory()->create();
        $row = guestRow(attributes: ['created_at' => now()->subHours(30)]);

        logInWithCookie($user);

        expect($row->fresh()->user_id)->toBe($user->id);
    });

    it('defaults the window to 24 hours', function () {
        expect(config('lamma.guest_claim_hours'))->toBe(24);
    });

    it('claims nothing without the lamma_guest cookie', function () {
        $user = User::factory()->create();
        $row = guestRow();

        logInWithCookie($user, token: null);

        expect($row->fresh()->user_id)->toBeNull();
    });

    it('claims nothing with a malformed cookie', function () {
        $user = User::factory()->create();
        $row = guestRow();

        logInWithCookie($user, token: 'short');

        expect($row->fresh()->user_id)->toBeNull();
    });

    it('never claims another device\'s results', function () {
        $user = User::factory()->create();
        $mine = guestRow(GUEST);
        $theirs = guestRow(OTHER_DEVICE);

        logInWithCookie($user);

        expect($mine->fresh()->user_id)->toBe($user->id)
            ->and($theirs->fresh()->user_id)->toBeNull();
    });

    it('never takes a row that already belongs to another account', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $claimed = guestRow(attributes: ['user_id' => $owner->id]);

        logInWithCookie($user);

        expect($claimed->fresh()->user_id)->toBe($owner->id);
    });

    it('leaves rows of players who joined as themselves alone', function () {
        $user = User::factory()->create();
        $other = RoomPlayer::factory()->forUser()->create();

        logInWithCookie($user);

        expect($other->fresh()->user_id)->not->toBe($user->id);
    });

    it('never turns the host into a player of their own room', function () {
        $room = Room::factory()->create();
        $hosted = guestRow(room: $room);
        $elsewhere = guestRow();

        logInWithCookie($room->host);

        expect($hosted->fresh()->user_id)->toBeNull()
            ->and($elsewhere->fresh()->user_id)->toBe($room->host->id);
    });

    it('ignores admin logins', function () {
        $row = guestRow();
        app()->instance('request', request()->duplicate(cookies: ['lamma_guest' => GUEST]));

        event(new Login('admin', Admin::factory()->create(), false));

        expect($row->fresh()->user_id)->toBeNull();
    });
});

describe('one row per user per room', function () {
    it('skips a guest row in a room where the user already has a row, and claims the others', function () {
        $user = User::factory()->create();
        $sameRoom = Room::factory()->create();
        $ownRow = RoomPlayer::factory()->for($sameRoom)->forUser($user)->create();
        $guestInSameRoom = guestRow(room: $sameRoom);
        $guestElsewhere = guestRow();

        logInWithCookie($user);

        expect($guestInSameRoom->fresh()->user_id)->toBeNull()
            ->and($guestElsewhere->fresh()->user_id)->toBe($user->id)
            ->and($sameRoom->players()->where('user_id', $user->id)->count())->toBe(1)
            ->and($ownRow->fresh()->user_id)->toBe($user->id);
    });

    it('is safe to run twice (Registered is followed by Login)', function () {
        $user = User::factory()->create();
        $row = guestRow();
        app()->instance('request', request()->duplicate(cookies: ['lamma_guest' => GUEST]));

        event(new Registered($user));
        event(new Login('web', $user, false));

        expect($row->fresh()->user_id)->toBe($user->id)
            ->and(RoomPlayer::where('user_id', $user->id)->count())->toBe(1);
    });
});

it('runs in a transaction: if one row fails to claim, none are', function () {
    $user = User::factory()->create();
    $first = guestRow();
    $second = guestRow();
    app()->instance('request', request()->duplicate(cookies: ['lamma_guest' => GUEST]));

    RoomPlayer::updating(function (RoomPlayer $row) use ($second) {
        if ($row->is($second)) {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => event(new Login('web', $user, false)))->toThrow(RuntimeException::class);

    expect($first->fresh()->user_id)->toBeNull()->and($second->fresh()->user_id)->toBeNull();
});

describe('saved results', function () {
    it('counts only rows tied to an account', function () {
        $user = User::factory()->create();
        $guest = guestRow();
        $registered = RoomPlayer::factory()->forUser($user)->create();

        expect(RoomPlayer::savedToAccount()->pluck('id')->all())->toBe([$registered->id]);

        logInWithCookie($user);

        expect(RoomPlayer::savedToAccount()->pluck('id')->sort()->values()->all())->toBe(collect([$registered->id, $guest->id])->sort()->values()->all());
    });
});
