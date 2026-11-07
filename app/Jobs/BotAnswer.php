<?php

namespace App\Jobs;

use App\Game\Bots;
use App\Game\GameEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A demo bot answers the question it was queued for, at the moment chosen when the question opened (App\Game\Bots::plan). It only looks at the game's
 * current state, so running it late, twice, or after the reveal changes nothing.
 */
class BotAnswer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $roomId,
        public readonly int $playerId,
        public readonly int $position,
        public readonly bool $correct,
    ) {}

    public function handle(Bots $bots, GameEngine $engine): void
    {
        $bots->answer($engine, $this->roomId, $this->playerId, $this->position, $this->correct);
    }
}
