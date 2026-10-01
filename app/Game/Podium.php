<?php

namespace App\Game;

/**
 * The podium for a final ranking (Scoreboard::ranking): one step per rank from 1 to 3 that has somebody on it. Players who share
 * a rank share the step (two tied winners both stand on the first step, and the next player is third, as in the ranking), and
 * a game with fewer than three players just has fewer steps. Nobody on the podium scored nothing: if the top score is 0
 * there is no winner.
 */
class Podium
{
    /**
     * @param  list<array{rank: int, player: array{id: int, nickname: string, locale: string}, total: int, gained: int}>  $ranking
     * @return list<array{rank: int, players: list<array{id: int, nickname: string, locale: string, total: int}>}> ordered by rank
     */
    public function steps(array $ranking): array
    {
        $steps = [];

        foreach ($ranking as $row) {
            if ($row['rank'] > 3) {
                break;
            }

            $steps[$row['rank']]['rank'] = $row['rank'];
            $steps[$row['rank']]['players'][] = [...$row['player'], 'total' => $row['total']];
        }

        ksort($steps);

        return array_values($steps);
    }

    /**
     * Whoever shares the top score, as long as it is more than nothing.
     *
     * @param  list<array{rank: int, player: array{id: int, nickname: string, locale: string}, total: int, gained: int}>  $ranking
     * @return list<array{id: int, nickname: string, locale: string, total: int}>
     */
    public function winners(array $ranking): array
    {
        $winners = [];

        foreach ($ranking as $row) {
            if ($row['rank'] === 1 && $row['total'] > 0) {
                $winners[] = [...$row['player'], 'total' => $row['total']];
            }
        }

        return $winners;
    }
}
