<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

class RoomPolicy
{
    /** Only the room's host may open and drive the host screen. Admins use Filament, not this screen. */
    public function host(User $user, Room $room): bool
    {
        return $room->host_id === $user->id;
    }
}
