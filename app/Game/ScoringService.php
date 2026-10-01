<?php

namespace App\Game;

use App\Models\RoomQuestion;
use Carbon\CarbonInterface;

/**
 * How many points an answer is worth. Flat for now: +100 for a correct answer, 0 otherwise. The answer time and the
 * question are passed in so a speed bonus can be added here later without touching the engine or the screens.
 */
class ScoringService
{
    public const POINTS_PER_CORRECT_ANSWER = 100;

    public function pointsFor(bool $correct, ?CarbonInterface $answeredAt = null, ?RoomQuestion $question = null): int
    {
        return $correct ? self::POINTS_PER_CORRECT_ANSWER : 0;
    }
}
