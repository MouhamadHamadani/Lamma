<?php

namespace App\Game;

use RuntimeException;

/** This address started too many demo games in the last hour. `retryAfter` is in seconds. */
class DemoLimitReached extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many demo games from this address.');
    }
}
