<?php

namespace App\Filament\Widgets;

use App\Game\GameStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The funnel of the last 30 days, from the database (not from Google Analytics): rooms created, games started, games played to the end, how many were played
 * again, the average number of people, and the demo as a figure of its own. Demo games and the bots are in none of the totals.
 */
class FunnelOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Funnel · last 30 days';

    protected ?string $description = 'Real games only. Demo games and their bots are not in these numbers.';

    protected function getStats(): array
    {
        $funnel = app(GameStats::class)->funnel(30);
        $demo = $funnel['demo'];

        return [
            Stat::make('Rooms created', $funnel['created'])->description('by hosts with an account'),
            Stat::make('Games started', $funnel['started'])->description($this->share($funnel['started'], $funnel['created'], 'of rooms created')),
            Stat::make('Games finished', $funnel['finished'])->description($this->share($funnel['finished'], $funnel['started'], 'of games started')),
            Stat::make('Rematch rate', $funnel['rematch_rate'] === null ? '–' : $funnel['rematch_rate'].'%')
                ->description("{$funnel['rematched']} of {$funnel['finished']} finished games were played again"),
            Stat::make('Average players', $funnel['average_players'] ?? '–')->description('per started game, bots not counted'),
            Stat::make('Demo games', $demo['started'])
                ->description("{$demo['finished']} played to the end · {$demo['converted']} signed up on the same browser ".($demo['started'] > 0 ? '('.(GameStats::share($demo['converted'], $demo['started']) ?? 0).'%)' : ''))
                ->color('info'),
        ];
    }

    private function share(int $part, int $whole, string $of): string
    {
        $share = GameStats::share($part, $whole);

        return $share === null ? 'nothing to compare yet' : "{$share}% {$of}";
    }
}
