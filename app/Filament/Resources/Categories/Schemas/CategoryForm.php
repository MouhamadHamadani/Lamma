<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Category;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CategoryForm
{
    /** @param bool $withParent false inside a parent's Subcategories tab, where the parent is already known */
    public static function configure(Schema $schema, bool $withParent = true): Schema
    {
        return $schema
            ->components([
                Grid::make(2)->schema([
                    TextInput::make('name.ar')
                        ->label('Name (Arabic)')
                        ->required()
                        ->maxLength(100)
                        ->extraInputAttributes(['dir' => 'rtl']),
                    TextInput::make('name.en')
                        ->label('Name (English)')
                        ->required()
                        ->maxLength(100)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, Set $set, string $operation) {
                            if ($operation === 'create') {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                ])->columnSpanFull(),
                ...($withParent ? [self::parentSelect()] : []),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Generated from the English name when creating.'),
                TextInput::make('icon')
                    ->maxLength(255)
                    ->helperText('Emoji or icon name shown next to the category.'),
                Toggle::make('is_active')
                    ->default(true),
                TextInput::make('sort_order')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }

    /** Only top-level categories are offered (one level of subcategories), and never the category itself. */
    private static function parentSelect(): Select
    {
        return Select::make('parent_id')
            ->label('Parent category')
            ->placeholder('None: a top-level category')
            ->helperText('Leave empty for a top-level category. A subcategory sits under a top-level one and wears its colour.')
            ->options(fn (?Category $record): array => Category::query()
                ->topLevel()
                ->when($record?->exists, fn ($query) => $query->whereKeyNot($record->getKey()))
                ->orderBy('sort_order')->orderBy('id')
                ->get()->pluck('name', 'id')->all())
            ->searchable()
            // A category that has subcategories cannot move under another one; the select is locked instead of failing on save.
            ->disabled(fn (?Category $record): bool => (bool) $record?->children()->exists())
            ->rule(fn (?Category $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                if ($problem = Category::parentProblem(filled($value) ? (int) $value : null, $record?->exists ? $record : null)) {
                    $fail($problem);
                }
            });
    }
}
