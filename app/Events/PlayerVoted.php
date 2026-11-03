<?php

namespace App\Events;

/**
 * Someone voted. Only the votes per category and how many have voted: never who voted for what (that stays on the voter's own phone until the
 * result, and then only the host screen draws it).
 */
class PlayerVoted extends RoomEvent
{
    /**
     * @param  array<int, int>  $counts  category id => votes, in display order
     */
    public function __construct(string $roomCode, public readonly int $round, public readonly array $counts, public readonly int $voted, public readonly int $expected)
    {
        parent::__construct($roomCode);
    }

    protected function payload(): array
    {
        return [
            'round' => $this->round,
            'counts' => collect($this->counts)->map(fn (int $votes, int $id) => ['category_id' => $id, 'votes' => $votes])->values()->all(),
            'voted' => $this->voted,
            'expected' => $this->expected,
        ];
    }
}
