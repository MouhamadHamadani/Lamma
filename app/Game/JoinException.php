<?php

namespace App\Game;

use RuntimeException;

/** Why a player could not join. `field` is the form field the message belongs under. */
class JoinException extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function roomNotFound(): self
    {
        return new self('code', __("We couldn't find that room"));
    }

    public static function alreadyStarted(): self
    {
        return new self('code', __('This game has already started'));
    }

    public static function nicknameTaken(): self
    {
        return new self('nickname', __('That nickname is taken in this room'));
    }

    public static function isHost(): self
    {
        return new self('code', __("You're the host of this room, so you can't play in it."));
    }
}
