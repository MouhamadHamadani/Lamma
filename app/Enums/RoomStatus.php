<?php

namespace App\Enums;

enum RoomStatus: string
{
    case Lobby = 'lobby';
    case Playing = 'playing';
    case Finished = 'finished';
}
