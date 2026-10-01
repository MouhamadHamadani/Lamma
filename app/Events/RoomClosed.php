<?php

namespace App\Events;

use App\Models\Room;

/** The host closed the room. Phones show a message and go home. */
class RoomClosed extends RoomEvent
{
    public function __construct(Room $room)
    {
        parent::__construct($room->code);
    }
}
