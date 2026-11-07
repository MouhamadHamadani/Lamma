<?php

namespace App\Filament\Resources\Questions\Tables;

use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Game\GameStats;
use App\Game\QuestionImage;
use App\Models\Category;
use App\Models\Question;
use App\Support\CategoryOptions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category.parent', 'options'])->withCount([
                // Asked in a room (it was shown), and what players answered: for "Times played" and "% correct".
                'roomQuestions as times_played' => fn (Builder $asked) => $asked->whereNotNull('room_questions.started_at'),
                'answers as answers_count',
                'answers as correct_answers_count' => fn (Builder $answers) => $answers->where('player_answers.is_correct', true),
            ]))
            ->columns([
                // The picture, if any, as a small thumbnail (a closed-over SVG or WebP of ours).
                ImageColumn::make('image_path')
                    ->label('Picture')
                    ->disk(QuestionImage::DISK)
                    ->visibility('public')
                    ->imageHeight(36)
                    ->toggleable(),
                TextColumn::make('text')
                    // current locale, else whichever language exists
                    ->state(fn (Question $record): ?string => $record->text ?: collect($record->getTranslations('text'))->first())
                    ->limit(70)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->state(fn (Question $record): string => $record->category->fullName()),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('difficulty')
                    ->badge(),
                TextColumn::make('times_played')
                    ->label('Times played')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('percent_correct')
                    ->label('% correct')
                    ->state(function (Question $record): string {
                        $answers = (int) $record->getAttribute('answers_count');
                        $percent = GameStats::percentCorrect($answers, $answers - (int) $record->getAttribute('correct_answers_count'));

                        return $percent === null ? '—' : $percent.'%';
                    })
                    // a question nobody answered sorts as 0%
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw('(correct_answers_count * 1.0 / CASE WHEN answers_count = 0 THEN 1 ELSE answers_count END) '.($direction === 'desc' ? 'desc' : 'asc'))),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('translated')
                    ->state(fn (Question $record): string => $record->isTranslatedIn(self::LOCALES) ? 'Translated' : 'Missing')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Translated' ? 'success' : 'danger'),
                // Complete for its type (options, number and tolerance, picture file): the reasons are in the tooltip. A game never draws an incomplete question.
                IconColumn::make('complete')
                    ->label('Complete')
                    ->state(fn (Question $record): bool => $record->problems() === [])
                    ->boolean()
                    ->tooltip(fn (Question $record): ?string => $record->problems() === [] ? null : implode(' ', $record->problems())),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                // A top-level category: its own questions and those of all its subcategories.
                SelectFilter::make('category')
                    ->options(fn (): array => Category::query()->topLevel()->orderBy('sort_order')->orderBy('id')->get()->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('questions.category_id', Category::query()->where('id', $data['value'])->orWhere('parent_id', $data['value'])->select('id'))
                        : $query),
                // One subcategory, listed under its parent.
                SelectFilter::make('subcategory')
                    ->options(fn (): array => CategoryOptions::subcategories())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->where('questions.category_id', $data['value']) : $query),
                SelectFilter::make('difficulty')
                    ->options(Difficulty::class),
                SelectFilter::make('type')
                    ->options(QuestionType::class),
                SelectFilter::make('picture')
                    ->label('Picture')
                    ->options(['with' => 'With a picture', 'without' => 'Without a picture'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'with' => $query->whereNotNull('questions.image_path'),
                        'without' => $query->whereNull('questions.image_path'),
                        default => $query,
                    }),
                TernaryFilter::make('is_active')
                    ->label('Active'),
                // Not playable: not translated, or not complete for its type (a true/false question that is not exactly True and False, a number question
                // without its number...). The picture's file is only checked in the Complete column: a database cannot look at a disk.
                Filter::make('incomplete')
                    ->label('Incomplete')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNotIn('questions.id', Question::withTrashed()->playable(self::LOCALES)->select('questions.id'))),
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
