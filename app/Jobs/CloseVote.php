<?php

namespace App\Jobs;

use App\Game\GameEngine;
use App\Game\Transition;
use App\Models\Room;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Vote mode: time is up for a category vote: close it. Dispatched with a delay when the vote opens. Like RevealQuestion it only looks at the current
 * state, so running it late, twice, after everyone voted early or after the host's "Skip vote" changes nothing. It carries the deadline it was queued for:
 * when the host's "+10 s" has moved it, this job is stale and goes away, and the one queued for the new deadline closes the vote.
 */
class CloseVote implements ShouldQueue
{
    use Queueable;

    /** A database queue stores delays in whole seconds, so the job can run a moment early and has to try again. */
    public int $tries = 10;

    public function __construct(public readonly int $roomId, public readonly int $round, public readonly ?int $deadlineMs = null) {}

    public function handle(GameEngine $engine): void
    {
        $room = Room::find($this->roomId);
        if ($room === null) {
            return;
        }

        if ($engine->closeVote($room, $this->round, deadlineMs: $this->deadlineMs) === Transition::TooEarly) {
            $this->release(1);
        }
    }
}
