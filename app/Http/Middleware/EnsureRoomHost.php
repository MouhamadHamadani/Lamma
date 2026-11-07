<?php

namespace App\Http\Middleware;

use App\Models\Room;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The host screen (/host/{room}) is for the room's host only. A real room needs a logged-in host account, exactly as before: guests are sent to log in and
 * every other user gets a 403. A DEMO room has no account host: only the browser holding its host cookie gets in, and everyone else a 403 (RoomPolicy::host).
 *
 * Not a subclass of the `auth` middleware on purpose: Laravel runs those before route model binding, and this one needs the bound Room.
 */
class EnsureRoomHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $room = $request->route('room');

        if (! $room instanceof Room) {
            abort(404);
        }

        if (! $room->is_demo && ! Auth::guard('web')->check()) {
            throw new AuthenticationException('Unauthenticated.', ['web'], route('login'));
        }

        Gate::authorize('host', $room);

        return $next($request);
    }
}
