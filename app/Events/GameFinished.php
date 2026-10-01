<?php

namespace App\Events;

use App\Game\Scoreboard;
use App\Models\Room;

/** The last question is done. The final ranking is in the payload; the results screens come next. */
class GameFinished extends RoomEvent
{
    /** @var list<array<string, mixed>> */
    public readonly array $ranking;

    public function __construct(Room $room)
    {
        parent::__construct($room->code);

        $this->ranking = app(Scoreboard::class)->ranking($room);
    }

    protected function payload(): array
    {
        return ['ranking' => $this->ranking];
    }
}
