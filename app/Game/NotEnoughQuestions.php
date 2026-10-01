<?php

namespace App\Game;

use RuntimeException;

/** The chosen categories and difficulty do not have enough playable questions for the requested game length. */
class NotEnoughQuestions extends RuntimeException
{
    public function __construct(public readonly int $available, public readonly int $needed)
    {
        parent::__construct("Only {$available} playable questions for a game of {$needed}.");
    }
}
