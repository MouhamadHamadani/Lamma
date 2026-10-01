<?php

namespace App\Filament\Widgets;

use App\Game\GameStats;
use Filament\Widgets\ChartWidget;

/** Categories by how many of their questions were asked in rooms. */
class MostPlayedCategoriesChart extends ChartWidget
{
    protected static ?int $sort = 11;

    protected ?string $heading = 'Most-played categories';

    protected ?string $description = 'Questions asked per category.';

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $categories = app(GameStats::class)->mostPlayedCategories(8);

        return [
            'datasets' => [[
                'label' => 'Questions asked',
                'data' => $categories->pluck('room_questions_count')->all(),
                'backgroundColor' => '#FF5A5F',
                'borderColor' => '#1B1F4B',
                'borderWidth' => 2,
            ]],
            'labels' => $categories->map(fn ($category) => $category->getTranslation('name', 'en', false) ?: $category->name)->all(),
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
