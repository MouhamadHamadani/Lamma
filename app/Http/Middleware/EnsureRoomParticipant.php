<?php

namespace App\Http\Middleware;

use App\Game\PlayerIdentity;
use App\Models\Room;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phone screens (/play/{room}) are for the room's participants only, found by user or guest token.
 * Anyone else is sent to the join screen with the code filled in. The phone shows the player's own language.
 */
class EnsureRoomParticipant
{
    public function __construct(private readonly PlayerIdentity $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $room = $request->route('room');
        $player = $room instanceof Room ? $this->identity->playerIn($room) : null;

        if ($player === null) {
            return redirect()->route('join', ['code' => $room instanceof Room ? $room->code : null]);
        }

        app()->setLocale($player->locale);

        return $next($request);
    }
}
