<?php

namespace App\Jobs;

use App\Game\GameEngine;
use App\Game\Transition;
use App\Models\Room;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The 3 - 2 - 1 - Go! countdown is over: open question 1. Dispatched with a delay when the game starts. Like RevealQuestion it looks at the
 * current state and exits if the host screen's tick got there first.
 */
class StartQuestion implements ShouldQueue
{
    use Queueable;

    /** A database queue stores delays in whole seconds, so the job can run a moment early and has to try again. */
    public int $tries = 10;

    public function __construct(public readonly int $roomId) {}

    public function handle(GameEngine $engine): void
    {
        $room = Room::find($this->roomId);
        if ($room === null) {
            return;
        }

        if ($engine->startQuestion($room) === Transition::TooEarly) {
            $this->release(1);
        }
    }
}
