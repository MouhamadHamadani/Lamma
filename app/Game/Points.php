<?php

namespace App\Game;

/** What one answer scored: the base and the speed bonus, times the multiplier. `total` is what goes on the score. */
final readonly class Points
{
    public int $total;

    public function __construct(public int $base, public int $speedBonus, public int $multiplier = 1)
    {
        $this->total = ($base + $speedBonus) * $multiplier;
    }
}
