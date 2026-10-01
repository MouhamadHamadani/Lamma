<?php

namespace App\Filament\Widgets;

use App\Game\GameStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Rooms in use right now: lobbies, games being played, and players connected to them. */
class ActiveRooms extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Active rooms right now';

    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $rooms = app(GameStats::class)->activeRooms();

        return [
            Stat::make('In the lobby', $rooms['lobby'])->description('waiting to start')->color($rooms['lobby'] > 0 ? 'warning' : 'gray'),
            Stat::make('Playing', $rooms['playing'])->description('games in progress')->color($rooms['playing'] > 0 ? 'success' : 'gray'),
            Stat::make('Players connected', $rooms['players'])->description('in those rooms'),
        ];
    }
}
