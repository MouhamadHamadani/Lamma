<?php

namespace App\Jobs;

use App\Game\GameEngine;
use App\Game\Transition;
use App\Models\Room;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Time is up for a question: reveal it. Dispatched with a delay when the question starts. It only looks at the game's
 * current state, so running it late, twice, or after everyone answered early (or the host skipped ahead) changes nothing.
 */
class RevealQuestion implements ShouldQueue
{
    use Queueable;

    /** A database queue stores delays in whole seconds, so the job can run a moment early and has to try again. */
    public int $tries = 10;

    public function __construct(public readonly int $roomId, public readonly int $position) {}

    public function handle(GameEngine $engine): void
    {
        $room = Room::find($this->roomId);
        if ($room === null) {
            return;
        }

        if ($engine->reveal($room, $this->position) === Transition::TooEarly) {
            $this->release(1);
        }
    }
}
