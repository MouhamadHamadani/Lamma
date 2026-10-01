<?php

namespace App\Game;

use RuntimeException;

/** Why the game could not start. The message is shown to the host. */
class GameStartException extends RuntimeException
{
    public static function notHost(): self
    {
        return new self(__('Only the host can start the game.'));
    }

    public static function notInLobby(): self
    {
        return new self(__('This game has already started'));
    }

    public static function noPlayers(): self
    {
        return new self(__('Wait for at least one player to connect.'));
    }

    public static function notEveryoneReady(): self
    {
        return new self(__('Everyone needs to tap Ready first.'));
    }

    public static function notEnoughQuestions(int $available, int $needed): self
    {
        return new self(__('Only :available playable questions are left for a game of :needed.', ['available' => $available, 'needed' => $needed]));
    }

    public static function inProgress(): self
    {
        return new self(__('The game is already starting.'));
    }
}
