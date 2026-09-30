<?php

namespace App\Filament\Resources\Questions\Schemas;

use App\Enums\Difficulty;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class QuestionForm
{
    public const MIN_OPTIONS = 4;

    public const MAX_OPTIONS = 5;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Question')->schema([
                    Select::make('category_id')
                        ->label('Category')
                        ->relationship('category', 'name', fn (Builder $query) => $query->ordered())
                        ->preload()
                        ->searchable()
                        ->required(),
                    Select::make('difficulty')
                        ->options(Difficulty::class)
                        ->required(),
                    Grid::make(2)->schema([
                        Textarea::make('text.ar')
                            ->label('Text (Arabic)')
                            ->requiredWithout('text.en')
                            ->maxLength(500)
                            ->rows(3)
                            ->extraInputAttributes(['dir' => 'rtl']),
                        Textarea::make('text.en')
                            ->label('Text (English)')
                            ->requiredWithout('text.ar')
                            ->maxLength(500)
                            ->rows(3),
                    ])->columnSpanFull(),
                    FileUpload::make('image_path')
                        ->label('Image')
                        ->image()
                        ->directory('questions')
                        ->maxSize(2048),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                ])->columns(2),

                Section::make('Options')
                    ->description(sprintf('%d to %d options, exactly one correct.', self::MIN_OPTIONS, self::MAX_OPTIONS))
                    ->schema([
                        Repeater::make('options')
                            ->relationship()
                            ->hiddenLabel()
                            ->schema([
                                Grid::make(2)->schema([
                                    TextInput::make('text.ar')
                                        ->label('Option (Arabic)')
                                        ->requiredWithout('text.en')
                                        ->maxLength(255)
                                        ->extraInputAttributes(['dir' => 'rtl']),
                                    TextInput::make('text.en')
                                        ->label('Option (English)')
                                        ->requiredWithout('text.ar')
                                        ->maxLength(255),
                                ]),
                                Toggle::make('is_correct')
                                    ->label('Correct answer')
                                    ->default(false),
                            ])
                            ->itemLabel(fn (array $state): ?string => $state['text']['en'] ?? $state['text']['ar'] ?? null)
                            ->orderColumn('sort_order')
                            ->reorderable()
                            ->minItems(self::MIN_OPTIONS)
                            ->maxItems(self::MAX_OPTIONS)
                            ->defaultItems(self::MIN_OPTIONS)
                            ->addActionLabel('Add option')
                            ->rule(static::exactlyOneCorrect()),
                    ]),
            ]);
    }

    /** Validation rule for the options repeater. */
    public static function exactlyOneCorrect(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $correct = collect($value)->filter(fn ($option) => ! empty($option['is_correct']))->count();

            if ($correct !== 1) {
                $fail(__('Mark exactly one option as correct.'));
            }
        };
    }
}
