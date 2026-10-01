<?php

namespace App\Events;

use App\Models\Room;

/** The host pressed "Play again": the same players, a new room. Phones on the results screen follow to the new lobby. */
class RoomRestarted extends RoomEvent
{
    public function __construct(Room $room, public readonly string $newCode)
    {
        parent::__construct($room->code);
    }

    protected function payload(): array
    {
        return ['new_code' => $this->newCode];
    }
}
