<?php

namespace App\Game;

use App\Models\Room;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What the dashboard remembers about demos. A demo room is deleted two hours after it starts (`lamma:prune`), so before it goes it is tallied into
 * `demo_stats`, one row per day: how many were started, how many were played to the end, and how many people signed up on a device that had hosted a demo.
 * Nothing in it can be traced to a person, a code or a device.
 */
class DemoStats
{
    /** A demo room that is about to be deleted: it was started, and maybe it was played to the end. */
    public function tally(Room $room): void
    {
        $this->add($room->created_at ?? now(), started: 1, finished: $room->isCompleted() ? 1 : 0);
    }

    /** Someone registered on a device that hosted a demo within the cookie's lifetime (App\Listeners\AttributeDemoSignup). */
    public function converted(): void
    {
        $this->add(now(), converted: 1);
    }

    /**
     * The last $days days (today included), counting the demo rooms that have not been tallied yet because they are still alive.
     *
     * @return array{started: int, finished: int, converted: int}
     */
    public function summary(int $days = 30): array
    {
        $since = now()->subDays($days - 1)->startOfDay();

        $tallied = DB::table('demo_stats')->where('day', '>=', $since->toDateString())
            ->selectRaw('coalesce(sum(started), 0) as started, coalesce(sum(finished), 0) as finished, coalesce(sum(converted), 0) as converted')
            ->first();

        $live = Room::query()->where('is_demo', true)->where('created_at', '>=', $since);

        return [
            'started' => (int) ($tallied->started ?? 0) + (clone $live)->count(),
            'finished' => (int) ($tallied->finished ?? 0) + (clone $live)->completed()->count(),
            'converted' => (int) ($tallied->converted ?? 0),
        ];
    }

    private function add(CarbonInterface $at, int $started = 0, int $finished = 0, int $converted = 0): void
    {
        $day = $at->toDateString();

        DB::transaction(function () use ($day, $started, $finished, $converted) {
            DB::table('demo_stats')->insertOrIgnore(['day' => $day]);
            DB::table('demo_stats')->where('day', $day)->incrementEach(['started' => $started, 'finished' => $finished, 'converted' => $converted]);
        });
    }
}
