<?php

namespace App\Events;

use App\Models\Room;

/** The host started the game. Phones and the host screen move on from the lobby to the 3 - 2 - 1 - Go! countdown, which ends at countdown_ends_at (ISO, milliseconds). */
class GameStarted extends RoomEvent
{
    public readonly ?string $countdownEndsAt;

    public function __construct(Room $room)
    {
        parent::__construct($room->code);

        $this->countdownEndsAt = self::iso($room->countdown_ends_at);
    }

    protected function payload(): array
    {
        return ['countdown_ends_at' => $this->countdownEndsAt];
    }
}
