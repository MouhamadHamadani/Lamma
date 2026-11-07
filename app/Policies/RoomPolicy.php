<?php

namespace App\Policies;

use App\Game\PlayerIdentity;
use App\Models\Room;
use App\Models\User;

class RoomPolicy
{
    /**
     * Only the room's host may open and drive the host screen. Admins use Filament, not this screen. A demo room has no account host: only the browser
     * that holds its host cookie is the host (PlayerIdentity::hostFor), so a guest passes this check for a demo and for nothing else.
     */
    public function host(?User $user, Room $room): bool
    {
        if ($room->is_demo) {
            return app(PlayerIdentity::class)->hostFor($room) !== null;
        }

        return $user !== null && $room->host_id === $user->id;
    }
}
