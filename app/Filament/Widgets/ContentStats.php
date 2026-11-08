<?php

namespace App\Filament\Widgets;

use App\Enums\GameMode;
use App\Game\NicknameRefusals;
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
        $refusals = app(NicknameRefusals::class);
        $questions = Question::count();
        $translated = Question::translatedIn(['ar', 'en'])->count();

        return [
            Stat::make('Categories', Category::count())
                ->description(Category::active()->count().' active'),
            Stat::make('Questions', $questions)
                ->description("{$translated} fully translated")
                ->color($translated === $questions ? 'success' : 'warning'),
            Stat::make('Users', User::count()),
            Stat::make('Rooms', Room::real()->count())
                ->description(Room::real()->where('game_mode', GameMode::Vote)->count().' in Vote mode'),
            // A count only (App\Game\NicknameRefusals): never the word, never who tried. A figure that keeps climbing means people keep trying; a flat zero, that the list is quiet.
            Stat::make('Nicknames refused today', $refusals->today())
                ->description($refusals->lastDays(7).' in the last 7 days'),
        ];
    }
}
