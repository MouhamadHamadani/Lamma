<?php

use App\Game\JoinException;
use App\Game\PlayerIdentity;
use App\Game\RoomCodeGenerator;
use App\Game\RoomJoiner;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

function joinerFor(?User $user = null, ?string $token = null): RoomJoiner
{
    $request = Request::create('/join', 'GET', cookies: $token ? ['lamma_guest' => $token] : []);
    if ($user) {
        $request->setUserResolver(fn () => $user);
    }

    return new RoomJoiner(new PlayerIdentity($request));
}

function joinError(callable $join): JoinException
{
    try {
        $join();
    } catch (JoinException $e) {
        return $e;
    }

    test()->fail('Expected a JoinException');
}

describe('code normalising', function () {
    it('upper-cases, drops look-alike and other characters, and keeps at most six', function (string $typed, string $expected) {
        expect(RoomCodeGenerator::normalize($typed))->toBe($expected);
    })->with([
        'lower case' => ['k7mp9z', 'K7MP9Z'],
        'spaces and dashes' => [' k7-mp 9z ', 'K7MP9Z'],
        'look-alikes 0 O 1 I L' => ['0O1ILK', 'K'],
        'too long' => ['ABCDEFGHJK', 'ABCDEF'],
        'empty' => ['', ''],
        'arabic letters' => ['سلام', ''],
        'script tag' => ['<b>K7', 'BK7'],
    ]);
});

describe('joining as a guest', function () {
    it('creates a guest row with a fresh token, nickname and language', function () {
        $room = Room::factory()->create();

        $player = joinerFor()->join($room->code, 'Sara', 'ar');

        expect($player->room_id)->toBe($room->id)
            ->and($player->user_id)->toBeNull()
            ->and($player->guest_token)->toMatch('/^[A-Za-z0-9]{64}$/')
            ->and($player->nickname)->toBe('Sara')
            ->and($player->locale)->toBe('ar')
            ->and($player->is_ready)->toBeFalse()
            ->and($player->score)->toBe(0)
            ->and(Cookie::queued('lamma_guest')->getValue())->toBe($player->guest_token);
    });

    it('reuses the device\'s existing token for a second room', function () {
        $token = str_repeat('t', 64);

        $first = joinerFor(token: $token)->join(Room::factory()->create()->code, 'Sara', 'en');
        $second = joinerFor(token: $token)->join(Room::factory()->create()->code, 'Sara', 'en');

        expect($first->guest_token)->toBe($token)->and($second->guest_token)->toBe($token)
            ->and(Cookie::hasQueued('lamma_guest'))->toBeFalse();
    });

    it('accepts the code in any letter case and tidies the nickname', function () {
        $room = Room::factory()->create();

        $player = joinerFor()->join(strtolower($room->code), "  Sara   B \n", 'en');

        expect($player->room_id)->toBe($room->id)->and($player->nickname)->toBe('Sara B');
    });

    it('falls back to the default language for an unsupported one', function () {
        expect(joinerFor()->join(Room::factory()->create()->code, 'Sara', 'fr')->locale)->toBe(config('app.locale'));
    });
});

describe('joining as a logged-in user', function () {
    it('creates a row with user_id and no guest token or cookie', function () {
        $user = User::factory()->create();
        $room = Room::factory()->create();

        $player = joinerFor($user)->join($room->code, 'Sara', 'en');

        expect($player->user_id)->toBe($user->id)->and($player->guest_token)->toBeNull()
            ->and(Cookie::hasQueued('lamma_guest'))->toBeFalse();
    });

    it('keeps the host out of their own room', function () {
        $room = Room::factory()->create();

        $e = joinError(fn () => joinerFor($room->host)->join($room->code, 'Host', 'en'));

        expect($e->field)->toBe('code')->and($e->getMessage())->toContain('host')
            ->and($room->players()->count())->toBe(0);
    });

    it('lets a host play in someone else\'s room', function () {
        $host = User::factory()->create();
        Room::factory()->for($host, 'host')->create();

        expect(joinerFor($host)->join(Room::factory()->create()->code, 'Hosty', 'en'))->toBeInstanceOf(RoomPlayer::class);
    });
});

describe('errors', function () {
    it('says the room cannot be found for an unknown or malformed code', function (string $code) {
        $e = joinError(fn () => joinerFor()->join($code, 'Sara', 'en'));

        expect($e->field)->toBe('code')->and($e->getMessage())->toBe("We couldn't find that room");
    })->with(['unknown' => 'ZZZZZZ', 'short' => 'AB', 'empty' => '', 'look-alikes only' => '0O1IL0']);

    it('says the game has started once it is playing', function () {
        $room = Room::factory()->playing()->create();

        $e = joinError(fn () => joinerFor()->join($room->code, 'Sara', 'en'));

        expect($e->field)->toBe('code')->and($e->getMessage())->toBe('This game has already started')
            ->and($room->players()->count())->toBe(0);
    });

    it('treats a finished room as gone', function () {
        $room = Room::factory()->finished()->create();

        expect(joinError(fn () => joinerFor()->join($room->code, 'Sara', 'en'))->getMessage())->toBe("We couldn't find that room");
    });

    it('rejects a nickname taken in the room, ignoring case and spacing', function (string $taken) {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Sara']);

        $e = joinError(fn () => joinerFor()->join($room->code, $taken, 'en'));

        expect($e->field)->toBe('nickname')->and($e->getMessage())->toBe('That nickname is taken in this room')
            ->and($room->players()->count())->toBe(1);
    })->with(['same' => 'Sara', 'upper' => 'SARA', 'lower' => 'sara', 'padded' => '  Sara ']);

    it('treats Arabic nicknames as unique too', function () {
        $room = Room::factory()->create();
        joinerFor()->join($room->code, 'سارة', 'ar');

        expect(joinError(fn () => joinerFor()->join($room->code, 'سارة', 'ar'))->field)->toBe('nickname');
    });

    it('allows the same nickname in a different room', function () {
        RoomPlayer::factory()->for(Room::factory()->create())->create(['nickname' => 'Sara']);

        expect(joinerFor()->join(Room::factory()->create()->code, 'Sara', 'en')->nickname)->toBe('Sara');
    });
});

describe('rejoining', function () {
    it('gives a guest their own row back, with no duplicate and no new cookie', function () {
        $room = Room::factory()->create();
        $token = str_repeat('r', 64);
        $row = RoomPlayer::factory()->for($room)->create(['guest_token' => $token, 'nickname' => 'Sara']);

        $again = joinerFor(token: $token)->join($room->code, 'SomethingElse', 'ar');

        expect($again->is($row))->toBeTrue()
            ->and($row->fresh()->nickname)->toBe('Sara')
            ->and($room->players()->count())->toBe(1)
            ->and(Cookie::hasQueued('lamma_guest'))->toBeFalse();
    });

    it('gives a logged-in user their own row back', function () {
        $room = Room::factory()->create();
        $user = User::factory()->create();
        $row = RoomPlayer::factory()->for($room)->forUser($user)->create();

        expect(joinerFor($user)->join($room->code, 'Whatever', 'en')->is($row))->toBeTrue()
            ->and($room->players()->count())->toBe(1);
    });

    it('works while the game is playing, but a newcomer is still turned away', function () {
        $room = Room::factory()->playing()->create();
        $row = RoomPlayer::factory()->for($room)->create(['guest_token' => str_repeat('p', 64)]);

        expect(joinerFor(token: str_repeat('p', 64))->join($room->code, 'x', 'en')->is($row))->toBeTrue();
        expect(joinError(fn () => joinerFor(token: str_repeat('q', 64))->join($room->code, 'New', 'en'))->getMessage())
            ->toBe('This game has already started');
    });

    it('works after the room has finished (to see the results)', function () {
        $room = Room::factory()->finished()->create();
        $row = RoomPlayer::factory()->for($room)->create(['guest_token' => str_repeat('f', 64)]);

        expect(joinerFor(token: str_repeat('f', 64))->join($room->code, 'x', 'en')->is($row))->toBeTrue();
    });

    it('does not mistake another device for the same player, even with the same nickname', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => str_repeat('a', 64), 'nickname' => 'Sara']);

        expect(joinError(fn () => joinerFor(token: str_repeat('b', 64))->join($room->code, 'Sara', 'en'))->field)->toBe('nickname');
    });
});

describe('simultaneous joins', function () {
    it('lets the unique index decide when two players pick the same nickname at once', function () {
        $room = Room::factory()->create();

        // The other player's insert lands between our check and our insert.
        RoomPlayer::creating(function (RoomPlayer $row) use ($room) {
            RoomPlayer::withoutEvents(fn () => RoomPlayer::factory()->for($room)->create(['nickname' => $row->nickname]));
        });

        $e = joinError(fn () => joinerFor()->join($room->code, 'Sara', 'en'));

        expect($e->field)->toBe('nickname')->and($room->players()->count())->toBe(1);
    });

    it('treats the same device joining twice at once as one player', function () {
        $room = Room::factory()->create();
        $token = str_repeat('s', 64);

        RoomPlayer::creating(function (RoomPlayer $row) use ($room, $token) {
            RoomPlayer::withoutEvents(fn () => RoomPlayer::factory()->for($room)->create(['guest_token' => $token, 'nickname' => 'Other']));
        });

        $player = joinerFor(token: $token)->join($room->code, 'Sara', 'en');

        expect($player->nickname)->toBe('Other')->and($room->players()->count())->toBe(1);
    });
});
