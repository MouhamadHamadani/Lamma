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

    /** Never says which word: the same sentence for every nickname the filter refuses (App\Game\NicknameFilter). */
    public static function nicknameBlocked(): self
    {
        return new self('nickname', __('Please choose another nickname.'));
    }
}
