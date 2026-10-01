<?php

namespace App\Events;

use App\Game\Scoreboard;
use App\Models\Room;
use App\Models\RoomQuestion;

/** Every total after the question's points were added, ranked, with what each player gained on it. */
class ScoreboardUpdated extends RoomEvent
{
    /** @var list<array<string, mixed>> */
    public readonly array $ranking;

    public function __construct(Room $room, public readonly int $position, ?RoomQuestion $roomQuestion = null)
    {
        parent::__construct($room->code);

        $this->ranking = app(Scoreboard::class)->ranking($room, $roomQuestion);
    }

    protected function payload(): array
    {
        return ['position' => $this->position, 'ranking' => $this->ranking];
    }
}
