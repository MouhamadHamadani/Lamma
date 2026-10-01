<?php

namespace App\Jobs;

use App\Game\GameEngine;
use App\Game\Transition;
use App\Models\Room;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The reveal pause is over: start the next question, or finish the game after the last. Dispatched with a delay when a
 * question is revealed. Like RevealQuestion it checks the current state and exits if the game has moved on (the host's
 * "Next question" button may have got there first).
 */
class AdvanceQuestion implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public function __construct(public readonly int $roomId, public readonly int $position) {}

    public function handle(GameEngine $engine): void
    {
        $room = Room::find($this->roomId);
        if ($room === null) {
            return;
        }

        if ($engine->advance($room, $this->position) === Transition::TooEarly) {
            $this->release(1);
        }
    }
}
