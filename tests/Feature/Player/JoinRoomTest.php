<?php

use App\Livewire\Player\JoinRoom;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function joinPage(array $query = [], ?User $user = null, ?string $token = null): Testable
{
    if ($user) {
        test()->actingAs($user);
    }

    $livewire = Livewire::withQueryParams($query);
    if ($token) {
        $livewire = $livewire->withCookie('lamma_guest', $token);
    }

    return $livewire->test(JoinRoom::class);
}

function joinWith(Testable $page, string $code, string $nickname, string $language = 'en'): Testable
{
    return $page->set('code', $code)->set('nickname', $nickname)->set('language', $language)->call('join');
}

describe('the page', function () {
    it('is open to guests and logged-in users', function () {
        $this->get(route('join'))->assertOk()->assertSeeLivewire(JoinRoom::class);
        $this->actingAs(User::factory()->create())->get(route('join'))->assertOk();
    });

    it('has the room code, nickname and language fields, left-to-right', function () {
        $this->withSession(['locale' => 'en'])->get(route('join'))
            ->assertSee('Join a game')->assertSee('Type the code shown on the big screen.')
            ->assertSee('Room code')->assertSee('Your nickname')->assertSee('Play in')
            ->assertSee('data-test="code-input"', false)->assertSee('maxlength="6"', false)
            ->assertSee('data-test="nickname-input"', false)
            ->assertSee('data-test="language-en"', false)->assertSee('data-test="language-ar"', false)
            ->assertSee('Have an account?');
    });

    it('marks the room code and nickname inputs dir=ltr', function () {
        $html = $this->get(route('join'))->getContent();

        expect($html)->toMatch('/id="join-code"[^>]*dir="ltr"/')->and($html)->toMatch('/id="join-nickname"[^>]*dir="ltr"/');
    });

    it('is translated, right-to-left in Arabic', function () {
        $this->withSession(['locale' => 'ar'])->get(route('join'))
            ->assertSee('dir="rtl"', false)->assertSee('انضم إلى لعبة')->assertSee('رمز الغرفة')->assertSee('اسمك المستعار')
            ->assertDontSee('Your nickname');
    });

    it('prefills the code from ?code=, upper-cased and cleaned', function (string $typed, string $expected) {
        joinPage(['code' => $typed])->assertSet('code', $expected);
    })->with([['k7mp', 'K7MP'], ['k7-mp9z', 'K7MP9Z'], ['0O1ILK', 'K'], ['ABCDEFGHJK', 'ABCDEF'], ['', '']]);

    it('prefills the nickname from the account name, cut to 20 characters', function () {
        joinPage(user: User::factory()->create(['name' => 'Layla Haddad']))->assertSet('nickname', 'Layla Haddad');
        joinPage(user: User::factory()->create(['name' => 'A Very Long Name Indeed Yes']))->assertSet('nickname', 'A Very Long Name Ind');
    });

    it('leaves the nickname blank for guests', function () {
        joinPage()->assertSet('nickname', '');
    });

    it('defaults the language to the visitor\'s', function (string $locale) {
        app()->setLocale($locale);

        joinPage()->assertSet('language', $locale);
    })->with(['en', 'ar']);

    it('lets the player pick a language', function () {
        joinPage()->call('$set', 'language', 'ar')->assertSet('language', 'ar')->assertSeeHtml('aria-pressed="true"');
    });

    it('sends a device that is already in the room straight back to it', function () {
        $room = Room::factory()->create();
        $token = str_repeat('x', 64);
        RoomPlayer::factory()->for($room)->create(['guest_token' => $token]);

        joinPage(['code' => $room->code], token: $token)->assertRedirect(route('play', $room->code));
        joinPage(['code' => $room->code])->assertNoRedirect();
    });
});

describe('joining', function () {
    it('puts a guest in the room, issues the guest cookie and goes to the phone lobby', function () {
        $room = Room::factory()->create();

        joinWith(joinPage(), $room->code, 'Sara', 'ar')->assertHasNoErrors()->assertRedirect(route('play', $room->code));

        $player = $room->players()->sole();
        expect($player->nickname)->toBe('Sara')->and($player->locale)->toBe('ar')
            ->and($player->user_id)->toBeNull()
            ->and($player->guest_token)->toMatch('/^[A-Za-z0-9]{64}$/')
            ->and(Cookie::queued('lamma_guest')->getValue())->toBe($player->guest_token)
            ->and(Cookie::queued('lamma_guest')->isHttpOnly())->toBeTrue();
    });

    it('puts a logged-in user in the room by user_id, with no guest cookie', function () {
        $room = Room::factory()->create();
        $user = User::factory()->create(['name' => 'Layla']);

        joinWith(joinPage(user: $user), $room->code, 'Layla')->assertRedirect(route('play', $room->code));

        expect($room->players()->sole()->user_id)->toBe($user->id)
            ->and($room->players()->sole()->guest_token)->toBeNull()
            ->and(Cookie::hasQueued('lamma_guest'))->toBeFalse();
    });

    it('accepts a lower-case code with stray spaces and a padded nickname', function () {
        $room = Room::factory()->create();

        joinWith(joinPage(), ' '.strtolower($room->code).' ', '  Sara  B ')->assertRedirect(route('play', $room->code));

        expect($room->players()->sole()->nickname)->toBe('Sara B');
    });
});

describe('inline errors', function () {
    it('shows "We couldn\'t find that room" under the code for an unknown room', function () {
        joinWith(joinPage(), 'ZZZZZZ', 'Sara')
            ->assertHasErrors(['code'])->assertSee("We couldn't find that room")->assertSeeHtml('data-test="code-error"')->assertNoRedirect();
    });

    it('shows "This game has already started" once the game is playing', function () {
        $room = Room::factory()->playing()->create();

        joinWith(joinPage(), $room->code, 'Sara')->assertSee('This game has already started')->assertNoRedirect();

        expect($room->players()->count())->toBe(0);
    });

    it('shows "That nickname is taken in this room" under the nickname', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Sara']);

        joinWith(joinPage(), $room->code, 'sara')
            ->assertHasErrors(['nickname'])->assertSee('That nickname is taken in this room')->assertSeeHtml('data-test="nickname-error"')->assertNoRedirect();
    });

    it('replaces the last attempt\'s error instead of piling up errors', function () {
        $room = Room::factory()->create();
        $page = joinWith(joinPage(), 'ZZZZZZ', 'Sara')->assertSee("We couldn't find that room");

        $page->set('code', $room->code)->call('join');

        expect($page->errors()->get('code'))->toBe([]);
    });

    it('shows the errors in Arabic', function () {
        app()->setLocale('ar');

        joinWith(joinPage(), 'ZZZZZZ', 'Sara')->assertSee('لم نعثر على هذه الغرفة');
    });

    it('asks for a complete code and a usable nickname', function (string $code, string $nickname, string $field, string $message) {
        $room = Room::factory()->create();

        joinWith(joinPage(), $code === 'REAL' ? $room->code : $code, $nickname)->assertHasErrors($field)->assertSee($message)->assertNoRedirect();

        expect($room->players()->count())->toBe(0);
    })->with([
        'empty code' => ['', 'Sara', 'code', 'Enter the 6-character room code.'],
        'short code' => ['K7M', 'Sara', 'code', 'Enter the 6-character room code.'],
        'empty nickname' => ['REAL', '', 'nickname', 'Enter a nickname.'],
        'one-letter nickname' => ['REAL', 'S', 'nickname', 'Your nickname needs 2 to 20 characters.'],
        'long nickname' => ['REAL', str_repeat('a', 21), 'nickname', 'Your nickname needs 2 to 20 characters.'],
        'blank nickname' => ['REAL', '    ', 'nickname', 'Enter a nickname.'],
    ]);

    it('rejects an unsupported language', function () {
        $room = Room::factory()->create();

        joinWith(joinPage(), $room->code, 'Sara', 'fr')->assertHasErrors('language');
    });

    it('does not let a host join their own room', function () {
        $room = Room::factory()->create();

        joinWith(joinPage(user: $room->host), $room->code, 'Host')->assertSee("You're the host of this room")->assertNoRedirect();
    });
});

describe('rejoining', function () {
    it('takes a guest back into their row without a duplicate player', function () {
        $room = Room::factory()->create();
        $token = str_repeat('j', 64);
        $row = RoomPlayer::factory()->for($room)->create(['guest_token' => $token, 'nickname' => 'Sara']);

        joinWith(joinPage(token: $token), $room->code, 'Sara')->assertRedirect(route('play', $room->code));

        expect($room->players()->count())->toBe(1)->and($row->fresh()->nickname)->toBe('Sara');
    });

    it('takes a logged-in user back into their row', function () {
        $room = Room::factory()->create();
        $user = User::factory()->create();
        RoomPlayer::factory()->for($room)->forUser($user)->create();

        joinWith(joinPage(user: $user), $room->code, 'Anything')->assertRedirect(route('play', $room->code));

        expect($room->players()->count())->toBe(1);
    });

    it('works for a user whose guest row was claimed on login', function () {
        $room = Room::factory()->create();
        $user = User::factory()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => str_repeat('c', 64), 'user_id' => $user->id]);

        joinWith(joinPage(user: $user), $room->code, 'Whatever')->assertRedirect(route('play', $room->code));

        expect($room->players()->count())->toBe(1);
    });
});

describe('rate limiting', function () {
    beforeEach(fn () => RateLimiter::clear('join:127.0.0.1'));

    it('allows ten attempts a minute per IP and blocks the eleventh', function () {
        $page = joinPage();

        foreach (range(1, 10) as $attempt) {
            joinWith($page, 'ZZZZZZ', 'Sara')->assertSee("We couldn't find that room");
        }

        joinWith($page, 'ZZZZZZ', 'Sara')->assertSee('Too many attempts. Please try again in')->assertDontSee("We couldn't find that room");
    });

    it('does not even look the room up once blocked', function () {
        $room = Room::factory()->create();
        foreach (range(1, 10) as $attempt) {
            RateLimiter::hit('join:127.0.0.1', 60);
        }

        joinWith(joinPage(), $room->code, 'Sara')->assertHasErrors('code')->assertNoRedirect();

        expect($room->players()->count())->toBe(0);
    });

    it('counts successful joins too, so one IP cannot spray many rooms', function () {
        foreach (range(1, 10) as $attempt) {
            joinWith(joinPage(), Room::factory()->create()->code, 'Sara');
        }

        joinWith(joinPage(), Room::factory()->create()->code, 'Sara')->assertSee('Too many attempts');
    });

    it('limits each IP separately', function () {
        foreach (range(1, 10) as $attempt) {
            RateLimiter::hit('join:10.9.9.9', 60);
        }
        $room = Room::factory()->create();

        joinWith(joinPage(), $room->code, 'Sara')->assertRedirect(route('play', $room->code));
    });

    it('lets attempts through again after a minute', function () {
        foreach (range(1, 10) as $attempt) {
            RateLimiter::hit('join:127.0.0.1', 60);
        }
        $room = Room::factory()->create();

        $this->travel(61)->seconds();

        joinWith(joinPage(), $room->code, 'Sara')->assertRedirect(route('play', $room->code));
    });

    it('reads the limit from config', function () {
        config(['lamma.join_attempts_per_minute' => 2]);
        $page = joinPage();

        joinWith($page, 'ZZZZZZ', 'a1');
        joinWith($page, 'ZZZZZZ', 'a2');

        joinWith($page, 'ZZZZZZ', 'a3')->assertSee('Too many attempts');
    });
});
