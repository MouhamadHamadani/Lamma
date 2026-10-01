<?php

namespace App\Game;

/** What a game transition did. Every transition can be asked for twice (a late job, a double click): all but Done are no-ops. */
enum Transition: string
{
    /** It happened now: the question was revealed / the next question started. */
    case Done = 'done';

    /** The last question was over: the game finished. */
    case Finished = 'finished';

    /** Already done by an earlier call. */
    case Already = 'already';

    /** Not yet allowed (time is not up, the reveal pause is not over). A job should try again shortly. */
    case TooEarly = 'too_early';

    /** The game has moved on or is not running: nothing to do. */
    case Stale = 'stale';
}
