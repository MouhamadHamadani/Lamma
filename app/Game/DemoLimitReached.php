<?php

namespace App\Game;

use RuntimeException;

/** This address, or this browser's demo-host cookie, started too many demo games in the last hour. `retryAfter` is in seconds (the longer wait of the two limits). */
class DemoLimitReached extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many demo games from this address or browser.');
    }
}
