<?php

namespace App\Filament\Widgets;

use App\Game\GameStats;
use App\Models\Question;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** The 10 questions players got wrong most often, with the share of answers that were right. */
class HardestQuestions extends TableWidget
{
    protected static ?int $sort = 12;

    protected static ?string $heading = 'Questions most often answered wrong';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(GameStats::class)->hardestQuestionsQuery(10))
            ->columns([
                TextColumn::make('text')
                    ->state(fn (Question $record): ?string => $record->text ?: collect($record->getTranslations('text'))->first())
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('wrong_count')->label('Wrong answers'),
                TextColumn::make('answers_count')->label('Answers'),
                TextColumn::make('percent_correct')
                    ->label('% correct')
                    ->state(function (Question $record): string {
                        $percent = GameStats::percentCorrect((int) $record->getAttribute('answers_count'), (int) $record->getAttribute('wrong_count'));

                        return $percent === null ? '—' : $percent.'%';
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('No answers yet')
            ->emptyStateDescription('Questions appear here once players have answered them.');
    }
}
