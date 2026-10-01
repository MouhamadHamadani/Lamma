<?php

namespace App\Events;

use App\Models\Room;

/** The host started the game. Phones and the host screen move on from the lobby. */
class GameStarted extends RoomEvent
{
    public function __construct(Room $room)
    {
        parent::__construct($room->code);
    }
}
