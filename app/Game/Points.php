<?php

namespace App\Game;

/**
 * What one answer scored: the base, the speed bonus and (closest-number questions) the closest-guess bonus, times the multiplier. `total` is what goes on the score.
 * A closest-number answer has no speed bonus; its +50 for the closest guess is only known at the reveal, so it is added to the answer then.
 */
final readonly class Points
{
    public int $total;

    public function __construct(public int $base, public int $speedBonus, public int $multiplier = 1, public int $closestBonus = 0)
    {
        $this->total = ($base + $speedBonus + $closestBonus) * $multiplier;
    }
}
