<?php

namespace App\Enums;

/** How a category vote was decided (category_vote_rounds.outcome). */
enum VoteOutcome: string
{
    /** One category had the most votes. */
    case Votes = 'votes';

    /** Two or more had the most votes: a random pick among them. */
    case Tie = 'tie';

    /** Nobody voted: a random pick among the offered categories. */
    case NoVotes = 'no_votes';

    /** Only one category could be offered, so there was no vote at all (no vote screen). */
    case OnlyOption = 'only_option';
}
