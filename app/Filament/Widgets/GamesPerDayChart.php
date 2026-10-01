<?php

namespace App\Filament\Widgets;

use App\Game\GameStats;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** Games that ran to the end, per day, over the last 30 days. */
class GamesPerDayChart extends ChartWidget
{
    protected static ?int $sort = 10;

    protected ?string $heading = 'Games played per day';

    protected ?string $description = 'Games that ran to the end, last 30 days.';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $games = app(GameStats::class)->gamesPerDay(30);

        return [
            'datasets' => [[
                'label' => 'Games',
                'data' => array_values($games),
                'borderColor' => '#FF5A5F',
                'backgroundColor' => 'rgba(255, 90, 95, 0.15)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => array_map(fn (string $date) => CarbonImmutable::parse($date)->format('j M'), array_keys($games)),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
