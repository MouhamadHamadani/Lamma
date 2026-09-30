<?php

namespace App\Filament\Widgets;

use App\Models\Category;
use App\Models\Question;
use App\Models\Room;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ContentStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $questions = Question::count();
        $translated = Question::translatedIn(['ar', 'en'])->count();

        return [
            Stat::make('Categories', Category::count())
                ->description(Category::active()->count().' active'),
            Stat::make('Questions', $questions)
                ->description("{$translated} fully translated")
                ->color($translated === $questions ? 'success' : 'warning'),
            Stat::make('Users', User::count()),
            Stat::make('Rooms', Room::count()),
        ];
    }
}
