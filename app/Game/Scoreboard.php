<?php

namespace App\Game;

use App\Models\Room;
use App\Models\RoomQuestion;

/** The ranking of a room's players, and what each gained on a question. Ties share a rank (1, 1, 3). */
class Scoreboard
{
    /**
     * @return list<array{rank: int, player: array{id: int, nickname: string, locale: string}, total: int, gained: int}>
     */
    public function ranking(Room $room, ?RoomQuestion $question = null): array
    {
        $gained = $question ? $question->answers()->pluck('points', 'room_player_id') : collect();
        $rows = [];
        $rank = 0;
        $previous = null;

        foreach ($room->players()->orderByDesc('score')->orderBy('joined_at')->orderBy('id')->get() as $index => $player) {
            if ($player->score !== $previous) {
                $rank = $index + 1;
                $previous = $player->score;
            }

            $rows[] = [
                'rank' => $rank,
                'player' => ['id' => $player->id, 'nickname' => $player->nickname, 'locale' => $player->locale],
                'total' => $player->score,
                'gained' => (int) ($gained[$player->id] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Where each player stood before the question's points were added, by player id (for animating rows to their new place).
     *
     * @param  list<array{rank: int, player: array{id: int, nickname: string, locale: string}, total: int, gained: int}>  $ranking
     * @return array<int, int> player id => 0-based position before
     */
    public function previousPositions(array $ranking): array
    {
        $before = collect($ranking)->sortBy([fn ($a, $b) => ($b['total'] - $b['gained']) <=> ($a['total'] - $a['gained'])])->values();

        return $before->mapWithKeys(fn (array $row, int $index) => [$row['player']['id'] => $index])->all();
    }

    /** "2nd" in English; the plain number in Arabic (the phrase around it carries the meaning). */
    public static function ordinal(int $rank): string
    {
        return $rank.self::suffix($rank);
    }

    /** "nd" for 2 in English; nothing in Arabic. */
    public static function suffix(int $rank): string
    {
        if (app()->getLocale() === 'ar') {
            return '';
        }

        return in_array($rank % 100, [11, 12, 13], true) ? 'th' : match ($rank % 10) {
            1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th',
        };
    }
}
