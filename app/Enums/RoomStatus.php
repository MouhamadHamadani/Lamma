<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RoomStatus: string implements HasColor, HasLabel
{
    case Lobby = 'lobby';
    case Playing = 'playing';
    case Finished = 'finished';

    public function getLabel(): string
    {
        return match ($this) {
            self::Lobby => __('Lobby'),
            self::Playing => __('Playing'),
            self::Finished => __('Finished'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Lobby => 'gray',
            self::Playing => 'success',
            self::Finished => 'info',
        };
    }
}
