<?php

namespace App\Game;

use App\Enums\VoteOutcome;
use App\Game\CategoryVote as VoteRules;
use App\Models\Category;
use App\Models\CategoryVote;
use App\Models\CategoryVoteRound;
use App\Models\RoomPlayer;
use Illuminate\Support\Collection;

/**
 * A snapshot of a category vote on screen (see GameView::vote): open, or closed and showing its result until the next question. Read-only. Who voted
 * for what is only for the host screen's result and for a player's own vote; a phone never draws anyone else's.
 */
final class VoteState
{
    /**
     * @param  Collection<int, Category>  $options  in display order
     * @param  Collection<int, RoomPlayer>  $players  in join order
     * @param  Collection<int, CategoryVote>  $votes  by player id
     * @param  array<int, int>  $counts  category id => votes
     */
    public function __construct(
        public readonly CategoryVoteRound $round,
        public readonly int $position,
        public readonly Collection $options,
        public readonly Collection $players,
        public readonly Collection $votes,
        public readonly array $counts,
        public readonly int $voted,
        public readonly int $expected,
    ) {}

    public function isOpen(): bool
    {
        return $this->round->isOpen();
    }

    /** The host already pressed "+10 s" on this vote (once per round). */
    public function wasExtended(): bool
    {
        return $this->round->extended_at !== null;
    }

    /** The vote's full length, for the timers to draw against. */
    public function seconds(): int
    {
        return VoteRules::seconds() + ($this->wasExtended() ? VoteRules::EXTEND_SECONDS : 0);
    }

    /** The category this player voted for, if any. */
    public function voteOf(RoomPlayer $player): ?int
    {
        return $this->votes->get($player->id)?->category_id;
    }

    /** 0-based display index (A=0, B=1, C=2) of a category, or null. */
    public function indexOf(?int $categoryId): ?int
    {
        $index = $this->options->search(fn (Category $category) => $category->id === $categoryId);

        return $index === false ? null : (int) $index;
    }

    /** @return Collection<int, RoomPlayer> who voted for this category, in join order (for the host's result only) */
    public function votersOf(int $categoryId): Collection
    {
        return $this->players->filter(fn (RoomPlayer $player) => $this->voteOf($player) === $categoryId)->values();
    }

    public function winner(): ?Category
    {
        return $this->options->firstWhere('id', $this->round->winner_category_id);
    }

    public function wasTie(): bool
    {
        return $this->round->outcome === VoteOutcome::Tie;
    }

    public function noVotes(): bool
    {
        return $this->round->outcome === VoteOutcome::NoVotes;
    }

    /** @return list<int> display indexes of the categories that shared the most votes (a tie's shuffle runs across them) */
    public function tiedIndexes(): array
    {
        $top = max([0, ...array_values($this->counts)]);

        return array_values($this->options->keys()->filter(fn (int $index) => ($this->counts[$this->options[$index]->id] ?? 0) === $top)->all());
    }
}
