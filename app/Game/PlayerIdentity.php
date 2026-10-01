<?php

namespace App\Game;

use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Who is making this request? A logged-in user (web guard) or a guest, identified by a random token in an encrypted,
 * httpOnly cookie. Everything that needs "the current participant" asks this service instead of reading the cookie.
 *
 * Not a singleton: it is built per request/resolution, so it always sees the current Request.
 */
class PlayerIdentity
{
    public const TOKEN_LENGTH = 64;

    public function __construct(private readonly Request $request) {}

    public function user(): ?User
    {
        return $this->request->user('web');
    }

    /** The token from the cookie, or null when absent or malformed (a tampered or foreign value is ignored). */
    public function guestToken(): ?string
    {
        $token = $this->request->cookie(config('lamma.guest_cookie'));

        return is_string($token) && preg_match('/^[A-Za-z0-9]{'.self::TOKEN_LENGTH.'}$/', $token) ? $token : null;
    }

    /** The existing token, or a new one that is queued as the cookie on the response. */
    public function issueGuestToken(): string
    {
        $token = $this->guestToken();
        if ($token !== null) {
            return $token;
        }

        $token = Str::random(self::TOKEN_LENGTH);

        Cookie::queue(cookie(
            name: config('lamma.guest_cookie'),
            value: $token,
            minutes: (int) config('lamma.guest_cookie_days') * 24 * 60,
            path: '/',
            domain: config('session.domain'),
            secure: config('session.secure') ?? $this->request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        ));

        // Visible to code later in this same request (e.g. creating the row right after issuing).
        $this->request->cookies->set(config('lamma.guest_cookie'), $token);

        return $token;
    }

    /**
     * The room_players columns that identify this participant when a row is created: user_id for a logged-in user,
     * otherwise the guest token (issued if the device has none yet).
     *
     * @return array{user_id: int|null, guest_token: string|null}
     */
    public function attributes(): array
    {
        $user = $this->user();

        return $user
            ? ['user_id' => $user->id, 'guest_token' => null]
            : ['user_id' => null, 'guest_token' => $this->issueGuestToken()];
    }

    /**
     * This participant's row in the room, if any. A logged-in user's own row comes first; an unclaimed guest row from
     * the same device still counts, so logging in mid-lobby never locks someone out of the room they joined. A row
     * already claimed by another account does not (shared device).
     */
    public function playerIn(Room $room): ?RoomPlayer
    {
        $user = $this->user();
        if ($user && $player = $room->players()->where('user_id', $user->id)->first()) {
            return $player;
        }

        $token = $this->guestToken();
        if ($token === null) {
            return null;
        }

        return $room->players()
            ->where('guest_token', $token)
            ->when($user, fn ($query) => $query->whereNull('user_id'))
            ->first();
    }
}
