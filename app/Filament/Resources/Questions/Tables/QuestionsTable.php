<?php

namespace App\Filament\Resources\Questions\Tables;

use App\Enums\Difficulty;
use App\Models\Question;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QuestionsTable
{
    /** Locales a question must be complete in to count as "Translated". */
    private const LOCALES = ['ar', 'en'];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'options']))
            ->columns([
                TextColumn::make('text')
                    // current locale, else whichever language exists
                    ->state(fn (Question $record): ?string => $record->text ?: collect($record->getTranslations('text'))->first())
                    ->limit(70)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label('Category'),
                TextColumn::make('difficulty')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('translated')
                    ->state(fn (Question $record): string => $record->isTranslatedIn(self::LOCALES) ? 'Translated' : 'Missing')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Translated' ? 'success' : 'danger'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('category')
                    ->relationship('category', 'name', fn (Builder $query) => $query->ordered())
                    ->preload(),
                SelectFilter::make('difficulty')
                    ->options(Difficulty::class),
                TernaryFilter::make('is_active')
                    ->label('Active'),
                Filter::make('missing_translation')
                    ->label('Missing translation')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNotIn(
                        'questions.id',
                        Question::withTrashed()->translatedIn(self::LOCALES)->select('questions.id'),
                    )),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
