<?php

namespace App\Events;

use App\Models\Room;
use App\Models\RoomPlayer;

/** A room event about one player. The payload is a snapshot taken now, so it still works after the row is deleted. */
abstract class PlayerEvent extends RoomEvent
{
    /** @var array{id: int, nickname: string, locale: string, is_ready: bool} */
    public readonly array $player;

    public function __construct(Room $room, RoomPlayer $player)
    {
        parent::__construct($room->code);

        $this->player = $player->toBroadcast();
    }

    protected function payload(): array
    {
        return ['player' => $this->player];
    }
}
