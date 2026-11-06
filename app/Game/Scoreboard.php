<?php

namespace App\Game;

use App\Models\PlayerAnswer;
use App\Models\Room;
use App\Models\RoomQuestion;

/**
 * The ranking of a room's players, and what each gained on a question. Ties share a rank (1, 1, 3). Also the display data the screens animate:
 * how far a player climbed this round (rank_change) and how many answers in a row they have right (streaks). Neither changes any score.
 */
class Scoreboard
{
    /**
     * @return list<array{rank: int, player: array{id: int, nickname: string, locale: string, avatar_url?: string|null}, total: int, gained: int, rank_change: int}>
     */
    public function ranking(Room $room, ?RoomQuestion $question = null): array
    {
        $gained = $question ? $question->answers()->pluck('points', 'room_player_id') : collect();
        $photos = $room->settings->showPhotos;
        $players = $room->players()->orderByDesc('score')->orderBy('joined_at')->orderBy('id')->get();
        // What each player had before this question's points were added; rank_change compares the rank that gave with the rank now.
        $before = $players->mapWithKeys(fn ($player) => [$player->id => $player->score - (int) ($gained[$player->id] ?? 0)]);
        $rows = [];
        $rank = 0;
        $previous = null;

        foreach ($players as $index => $player) {
            if ($player->score !== $previous) {
                $rank = $index + 1;
                $previous = $player->score;
            }

            $rows[] = [
                'rank' => $rank,
                'player' => $player->toRanked($photos),
                'total' => $player->score,
                'gained' => (int) ($gained[$player->id] ?? 0),
                // The rank before this round minus the rank now: positive = moved up. 0 when there is no question (the final standings).
                'rank_change' => 1 + $before->filter(fn (int $total) => $total > $before[$player->id])->count() - $rank,
            ];
        }

        return $rows;
    }

    /**
     * How many right answers in a row each player has, up to and including this question (a wrong or missing answer breaks the run).
     * Display only: a streak scores nothing.
     *
     * @return array<int, int> player id => streak
     */
    public function streaks(Room $room, RoomQuestion $upTo): array
    {
        $positions = $room->roomQuestions()->where('position', '<=', $upTo->position)->pluck('position', 'id');
        $right = PlayerAnswer::query()->whereIn('room_question_id', $positions->keys())->where('is_correct', true)->get(['room_question_id', 'room_player_id'])
            ->groupBy('room_player_id')
            ->map(fn ($answers) => $answers->map(fn (PlayerAnswer $answer) => $positions[$answer->room_question_id])->flip());

        return $room->players()->pluck('id')->mapWithKeys(function (int $id) use ($right, $upTo) {
            $rightAt = $right[$id] ?? collect();
            $run = 0;
            for ($position = $upTo->position; $position >= 1 && $rightAt->has($position); $position--) {
                $run++;
            }

            return [$id => $run];
        })->all();
    }

    /**
     * Where each player stood before the question's points were added, by player id (for animating rows to their new place).
     *
     * @param  list<array{rank: int, player: array{id: int, nickname: string, locale: string, avatar_url?: string|null}, total: int, gained: int, rank_change: int}>  $ranking
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
