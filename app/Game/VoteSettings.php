<?php

namespace App\Game;

/** A Vote-mode room's setup (rooms.first_category_id, rooms.vote_every and room_category_pool), as the create-room screen and Play again pass it on. */
final readonly class VoteSettings
{
    /** How often the players vote, in questions, as the host can pick it. */
    public const EVERY = [3, 5, 10];

    /** At least this many categories to vote between, the first category included. */
    public const MIN_POOL = 3;

    /**
     * @param  list<int>  $poolIds  top-level category ids, the first category included
     */
    public function __construct(
        public int $firstCategoryId,
        public array $poolIds,
        public int $every = 5,
    ) {}
}
