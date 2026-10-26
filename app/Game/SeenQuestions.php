<?php

namespace App\Game;

use App\Models\Room;
use App\Models\RoomPlayer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What each participant has already met (the `question_seen` table), so a new game can prefer questions nobody in the room has seen.
 * A participant is a user (user_id) or a guest (their lamma_guest token). Rows are written when a question is revealed.
 */
class SeenQuestions
{
    /** Every player of the room has now seen this question. */
    public function record(Room $room, int $questionId): void
    {
        $now = now();
        $rows = $room->players()->get(['user_id', 'guest_token'])
            ->filter(fn (RoomPlayer $player) => $player->user_id !== null || $player->guest_token !== null)
            ->map(fn (RoomPlayer $player) => [
                'question_id' => $questionId,
                'user_id' => $player->user_id,
                'guest_token' => $player->user_id === null ? $player->guest_token : null,
                'room_id' => $room->id,
                'seen_at' => $now,
            ])
            ->values()->all();

        if ($rows !== []) {
            DB::table('question_seen')->insert($rows);
        }
    }

    /**
     * For each question any of these players has seen: how many of them, and when the latest of them saw it. Questions nobody saw are absent.
     *
     * @param  Collection<int, RoomPlayer>  $players
     * @return array<int, array{seen_by: int, last_seen: int}> keyed by question id
     */
    public function summarize(Collection $players): array
    {
        $users = $players->pluck('user_id')->filter()->unique()->values()->all();
        $tokens = $players->whereNull('user_id')->pluck('guest_token')->filter()->unique()->values()->all();
        if ($users === [] && $tokens === []) {
            return [];
        }

        $rows = DB::table('question_seen')
            ->where(fn ($query) => $query->whereIn('user_id', $users)->orWhereIn('guest_token', $tokens))
            ->groupBy('question_id', 'user_id', 'guest_token')
            ->select('question_id', 'user_id', 'guest_token', DB::raw('max(seen_at) as last_seen'))
            ->get();

        $summary = [];
        foreach ($rows as $row) {
            $question = (int) $row->question_id;
            $seenAt = CarbonImmutable::parse($row->last_seen)->getTimestamp();
            $summary[$question] ??= ['seen_by' => 0, 'last_seen' => 0];
            $summary[$question]['seen_by']++;
            $summary[$question]['last_seen'] = max($summary[$question]['last_seen'], $seenAt);
        }

        return $summary;
    }

    /**
     * Forget what guests saw longer ago than the claim window: after that their results can no longer be attached to an account either.
     *
     * @return int how many rows went
     */
    public function pruneGuests(): int
    {
        return DB::table('question_seen')->whereNull('user_id')->where('seen_at', '<', now()->subHours((int) config('lamma.guest_claim_hours')))->delete();
    }
}
