<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Models\Category;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // A parent, then its subcategories under it.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('parent')
                ->orderByRaw('coalesce(categories.parent_id, categories.id)')
                ->orderByRaw('case when categories.parent_id is null then 0 else 1 end')
                ->orderBy('categories.sort_order')->orderBy('categories.id'))
            ->columns([
                TextColumn::make('name')
                    ->state(fn (Category $record): string => $record->isSubcategory() ? '↳ '.$record->name : $record->name)
                    ->searchable(),
                TextColumn::make('parent.name')
                    ->label('Parent')
                    ->placeholder('—'),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('icon'),
                TextColumn::make('children_count')
                    ->counts('children')
                    ->label('Subcategories')
                    ->sortable(),
                TextColumn::make('questions_count')
                    ->counts('questions')
                    ->label('Questions')
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('Active'),
                TextColumn::make('sort_order')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('parent_id')
                    ->label('Parent')
                    ->options(fn (): array => Category::query()->topLevel()->orderBy('sort_order')->orderBy('id')->get()->pluck('name', 'id')->all()),
                Filter::make('top_level')
                    ->label('Top-level only')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNull('categories.parent_id')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        // A parent with subcategories cannot be deleted (the database refuses): say so instead of failing.
                        ->before(function (DeleteBulkAction $action, Collection $records) {
                            if (Category::query()->whereIn('parent_id', $records->modelKeys())->exists()) {
                                Notification::make()->danger()->title('Delete or move the subcategories first')
                                    ->body('A category that has subcategories cannot be deleted.')->send();
                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }
}
