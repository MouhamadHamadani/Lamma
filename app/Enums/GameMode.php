<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a room picks its questions. Classic: the host chooses the categories and every question is drawn at the start. Vote: the host chooses the
 * first category and a pool, and after every few questions the players vote on the category of the next batch (App\Game\CategoryVote).
 */
enum GameMode: string implements HasLabel
{
    case Classic = 'classic';
    case Vote = 'vote';

    public function getLabel(): string
    {
        return match ($this) {
            self::Classic => __('Classic'),
            self::Vote => __('Vote'),
        };
    }
}
