<?php

use App\Game\PlayerIdentity;
use App\Models\Admin;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withCredentials(); // getJson() sends no cookies unless asked

    // A throwaway endpoint inside the real web middleware group (cookie encryption, sessions, auth).
    Route::middleware('web')->get('/_identity', function (PlayerIdentity $identity) {
        return response()->json($identity->attributes());
    });
});

function guestCookie($response): ?string
{
    return $response->getCookie('lamma_guest')?->getValue();
}

describe('guest token', function () {
    it('issues a random 64-character token on a guest\'s first request', function () {
        $response = $this->getJson('/_identity')->assertOk();
        $token = guestCookie($response);

        expect($token)->toMatch('/^[A-Za-z0-9]{64}$/')
            ->and($response->json())->toBe(['user_id' => null, 'guest_token' => $token]);
    });

    it('stores it in an encrypted, httpOnly, same-site cookie that lasts 30 days', function () {
        $response = $this->getJson('/_identity');
        $token = guestCookie($response);
        $raw = $response->getCookie('lamma_guest', decrypt: false);

        expect($raw->getValue())->not->toBe($token)                   // encrypted on the wire
            ->and($raw->isHttpOnly())->toBeTrue()
            ->and(strtolower((string) $raw->getSameSite()))->toBe('lax')
            ->and($raw->getPath())->toBe('/')
            ->and(abs($raw->getExpiresTime() - now()->addDays(30)->getTimestamp()))->toBeLessThan(120);
        expect(app(EncryptCookies::class)->isDisabled('lamma_guest'))->toBeFalse();
    });

    it('reuses the token on later requests from the same device and does not set the cookie again', function () {
        $token = str_repeat('a1', 32);

        $response = $this->withCookie('lamma_guest', $token)->getJson('/_identity')->assertOk();

        expect($response->json('guest_token'))->toBe($token)
            ->and($response->getCookie('lamma_guest'))->toBeNull();
    });

    it('gives different devices different tokens', function () {
        $first = guestCookie($this->getJson('/_identity'));
        $second = guestCookie($this->getJson('/_identity'));

        expect($first)->not->toBe($second);
    });

    it('ignores a malformed cookie value and issues a fresh token', function (string $bad) {
        $response = $this->withCookie('lamma_guest', $bad)->getJson('/_identity');

        expect(guestCookie($response))->toMatch('/^[A-Za-z0-9]{64}$/')->not->toBe($bad);
    })->with(['too short' => 'abc', 'wrong characters' => '../../etc/passwd', 'too long' => str_repeat('a', 65)]);

    it('issues only one token per request however often it is asked', function () {
        Route::middleware('web')->get('/_twice', function (PlayerIdentity $identity) {
            return response()->json([$identity->issueGuestToken(), $identity->issueGuestToken(), $identity->attributes()['guest_token']]);
        });

        $response = $this->getJson('/_twice');
        [$a, $b, $c] = $response->json();

        expect($a)->toBe($b)->toBe($c)->toBe(guestCookie($response));
    });
});

describe('logged-in user', function () {
    it('is identified by user_id and gets no guest cookie', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/_identity')->assertOk();

        expect($response->json())->toBe(['user_id' => $user->id, 'guest_token' => null])
            ->and($response->getCookie('lamma_guest'))->toBeNull();
    });

    it('is not fooled by the admin guard', function () {
        $admin = Admin::factory()->create();

        $this->actingAs($admin, 'admin');
        Auth::shouldUse('web');

        expect($this->getJson('/_identity')->json('user_id'))->toBeNull();
    });
});

describe('finding the participant in a room', function () {
    it('finds a guest by their token and nobody else', function () {
        $room = Room::factory()->create();
        $mine = RoomPlayer::factory()->for($room)->create(['guest_token' => str_repeat('m', 64)]);
        RoomPlayer::factory()->for($room)->create(['guest_token' => str_repeat('o', 64)]);

        $this->withCookie('lamma_guest', str_repeat('m', 64));
        $identity = fn () => new PlayerIdentity(request()->duplicate(cookies: ['lamma_guest' => str_repeat('m', 64)]));

        expect($identity()->playerIn($room)?->is($mine))->toBeTrue();
        expect((new PlayerIdentity(request()->duplicate(cookies: ['lamma_guest' => str_repeat('z', 64)])))->playerIn($room))->toBeNull();
        expect((new PlayerIdentity(request()->duplicate()))->playerIn($room))->toBeNull();
    });

    it('finds a logged-in user by user_id', function () {
        $room = Room::factory()->create();
        $user = User::factory()->create();
        $row = RoomPlayer::factory()->for($room)->forUser($user)->create();
        RoomPlayer::factory()->for($room)->forUser()->create();

        $request = request()->duplicate();
        $request->setUserResolver(fn () => $user);

        expect((new PlayerIdentity($request))->playerIn($room)?->is($row))->toBeTrue();
    });

    it('lets a logged-in user keep using their own unclaimed guest row from this device', function () {
        $room = Room::factory()->create();
        $user = User::factory()->create();
        $guestRow = RoomPlayer::factory()->for($room)->create(['guest_token' => str_repeat('g', 64)]);

        $request = request()->duplicate(cookies: ['lamma_guest' => str_repeat('g', 64)]);
        $request->setUserResolver(fn () => $user);

        expect((new PlayerIdentity($request))->playerIn($room)?->is($guestRow))->toBeTrue();
    });

    it('does not hand a row claimed by another account to whoever logs in next on the same device', function () {
        $room = Room::factory()->create();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => str_repeat('g', 64), 'user_id' => $owner->id]);

        $request = request()->duplicate(cookies: ['lamma_guest' => str_repeat('g', 64)]);
        $request->setUserResolver(fn () => $stranger);

        expect((new PlayerIdentity($request))->playerIn($room))->toBeNull();
    });

    it('does not look in other rooms', function () {
        $user = User::factory()->create();
        RoomPlayer::factory()->for(Room::factory()->create())->forUser($user)->create();

        $request = request()->duplicate();
        $request->setUserResolver(fn () => $user);

        expect((new PlayerIdentity($request))->playerIn(Room::factory()->create()))->toBeNull();
    });
});
