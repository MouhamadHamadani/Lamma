<?php

namespace App\Events;

use App\Models\Room;
use App\Models\RoomPlayer;

/** Someone locked in an answer. Says who and how many have answered, never what they chose. */
class PlayerAnswered extends RoomEvent
{
    /** @var array{id: int, nickname: string} */
    public readonly array $player;

    public function __construct(Room $room, RoomPlayer $player, public readonly int $position, public readonly int $answered, public readonly int $expected)
    {
        parent::__construct($room->code);

        $this->player = ['id' => $player->id, 'nickname' => $player->nickname];
    }

    protected function payload(): array
    {
        return ['position' => $this->position, 'player' => $this->player, 'answered' => $this->answered, 'expected' => $this->expected];
    }
}
