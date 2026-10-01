<?php

namespace App\Game;

use RuntimeException;

/** Why an answer was not accepted. The player's screen simply shows whatever state the game is in; the reason is for logs and tests. */
class AnswerRejected extends RuntimeException
{
    public const CLOSED = 'closed';

    public const LATE = 'late';

    public const ALREADY_ANSWERED = 'already_answered';

    public const INVALID_OPTION = 'invalid_option';

    public const NOT_A_PLAYER = 'not_a_player';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Answer rejected: {$reason}");
    }
}
