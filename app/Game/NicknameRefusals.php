<?php

namespace App\Game;

use Illuminate\Support\Facades\DB;

/**
 * A tally of the nicknames the filter refused, one number per day (the `nickname_refusals` table), for the admin dashboard. It stores a COUNT and nothing else: never the
 * nickname, never the word that matched, never who tried. Only an actual refusal at a join or a rename counts; showing a refused stored name as "Player" does not.
 */
class NicknameRefusals
{
    /** One more name was refused today. Safe to call from two requests at once: the row is created if missing, then incremented in the database. */
    public function record(): void
    {
        $day = now()->toDateString();

        DB::table('nickname_refusals')->insertOrIgnore(['day' => $day, 'refused' => 0]);
        DB::table('nickname_refusals')->where('day', $day)->increment('refused');
    }

    /** Names refused today. */
    public function today(): int
    {
        return $this->lastDays(1);
    }

    /** Names refused in the last $days days, today included (7 = "this week": a rolling week, so it never resets on a Monday). */
    public function lastDays(int $days): int
    {
        return (int) DB::table('nickname_refusals')->where('day', '>=', now()->subDays(max(1, $days) - 1)->toDateString())->sum('refused');
    }
}
