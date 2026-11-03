<?php

namespace App\Events;

use App\Models\Category;
use App\Models\CategoryVoteRound;
use App\Models\Room;
use App\Support\CategoryStyle;

/**
 * Vote mode: the players vote on the category of the next batch. The offered categories in display order (every screen shows the same tiles), in
 * both languages, and the deadline. No counts yet.
 */
class VoteStarted extends RoomEvent
{
    /** @var array<string, mixed> */
    public readonly array $vote;

    public function __construct(Room $room, CategoryVoteRound $round)
    {
        parent::__construct($room->code);

        $categories = Category::query()->whereIn('id', $round->option_ids)->get()->keyBy('id');

        $this->vote = [
            'round' => $round->round,
            'after_position' => $round->round * (int) $room->vote_every,
            'options' => collect($round->option_ids)->filter(fn (int $id) => $categories->has($id))->map(fn (int $id) => [
                'id' => $id,
                'name' => self::translations($categories[$id], 'name'),
                'tint' => CategoryStyle::tintName($categories[$id]->slug),
                'icon' => CategoryStyle::icon($categories[$id]->slug),
            ])->values()->all(),
            'ends_at' => self::iso($round->ends_at),
        ];
    }

    protected function payload(): array
    {
        return ['vote' => $this->vote];
    }
}
