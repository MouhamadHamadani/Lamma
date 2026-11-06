<?php

namespace App\Events;

use App\Models\CategoryVoteRound;
use App\Models\Room;

/** Vote mode: the host gave the vote more time. The new deadline; the screens redraw their timers from the database. */
class VoteExtended extends RoomEvent
{
    public readonly int $round;

    public readonly ?string $endsAt;

    public function __construct(Room $room, CategoryVoteRound $round)
    {
        parent::__construct($room->code);

        $this->round = $round->round;
        $this->endsAt = self::iso($round->ends_at);
    }

    protected function payload(): array
    {
        return ['round' => $this->round, 'ends_at' => $this->endsAt];
    }
}
