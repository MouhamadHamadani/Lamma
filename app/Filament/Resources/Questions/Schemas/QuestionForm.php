<?php

namespace App\Filament\Resources\Questions\Schemas;

use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Game\QuestionImage;
use App\Game\QuestionImageException;
use App\Support\CategoryOptions;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class QuestionForm
{
    public const MIN_OPTIONS = 4;

    public const MAX_OPTIONS = 5;

    /** The longest fun fact: it shows in one or two lines on the big screen. */
    public const MAX_FACT = 300;

    public static function configure(Schema $schema): Schema
    {
        $is = fn (QuestionType $type): Closure => fn (Get $get): bool => self::typeOf($get('type')) === $type;

        return $schema
            ->columns(1)
            ->components([
                Section::make('Question')->schema([
                    // What kind of question it is decides which fields below are shown.
                    Select::make('type')
                        ->options(QuestionType::class)
                        ->default(QuestionType::MultipleChoice)
                        ->required()
                        ->native(false)
                        ->live()
                        ->helperText('Multiple choice: 4 or 5 options. True or false: two fixed answers. Closest number: players type a number and the closest guess wins.')
                        ->columnSpanFull(),
                    // Grouped by parent: a subcategory sits under its category, the category's own "(general)" questions first.
                    Select::make('category_id')
                        ->label('Category')
                        ->options(fn (): array => CategoryOptions::grouped())
                        ->searchable()
                        ->required()
                        ->exists('categories', 'id'),
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
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                ])->columns(2),

                Section::make('Picture')
                    ->description('Optional, for any type: it is shown above the answers on the big screen and on the phones. A real JPEG, PNG or WebP, up to 2 MB, at most 4096 pixels on a side. It is re-saved as WebP and fitted to 1200 x 900. Do not use a picture that gives the answer away: the alt text is always "Picture for this question".')
                    ->schema([
                        FileUpload::make('image_path')
                            ->label('Picture')
                            ->disk(QuestionImage::DISK)
                            ->visibility('public')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(QuestionImage::MAX_BYTES / 1024)
                            ->previewable()
                            ->imagePreviewHeight('180')
                            ->rule(fn (): Closure => static::realPicture())
                            // Never keep what the client sent: check the bytes, re-encode them (App\Game\QuestionImage) and keep that.
                            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                                try {
                                    return app(QuestionImage::class)->storeUpload($file);
                                } catch (QuestionImageException $exception) {
                                    // The rule above has said so already; this is the net under it: the question is not saved without its picture.
                                    throw ValidationException::withMessages(['data.image_path' => $exception->getMessage()]);
                                }
                            }),
                    ]),

                Section::make('Options')
                    ->description(sprintf('%d to %d options, exactly one correct.', self::MIN_OPTIONS, self::MAX_OPTIONS))
                    ->visible($is(QuestionType::MultipleChoice))
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
                            ->rule(fn (): Closure => static::exactlyOneCorrect()),
                    ]),

                Section::make('True or false')
                    ->description('The two answers are always True and False («صحيح» and «خطأ»), shown in that order. Write the question as a statement.')
                    ->visible($is(QuestionType::TrueFalse))
                    ->schema([
                        // Not a column: the two options are made from it when the question is saved (Question::syncTrueFalse).
                        Radio::make('true_false_answer')
                            ->label('The statement is')
                            ->options(['true' => 'True', 'false' => 'False'])
                            ->inline()
                            ->required(),
                    ]),

                Section::make('Closest number')
                    ->description('Players type a number. 100 points for the exact number, falling to 0 at the tolerance, and +50 for the closest guess. Use a plain number: no units, no separators.')
                    ->visible($is(QuestionType::ClosestNumber))
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('answer_number')
                                ->label('Correct number')
                                ->numeric()
                                ->required()
                                ->minValue(-1_000_000_000_000)
                                ->maxValue(1_000_000_000_000),
                            TextInput::make('tolerance')
                                ->label('Tolerance')
                                ->numeric()
                                ->minValue(0.0001)
                                ->maxValue(1_000_000_000_000)
                                ->helperText('How far off a guess may be and still score. Empty: a quarter of the number, at least 1. For a year, set it yourself (5 or 10).'),
                        ]),
                    ]),

                Section::make('Fun fact')
                    ->description('Optional. One or two short lines shown under the correct answer, never before it. Do not repeat the answer to the question in a way that matters before the reveal: it only shows after.')
                    ->schema([
                        Tabs::make('Fun fact')->tabs([
                            Tab::make('Arabic')->schema([
                                Textarea::make('explanation.ar')
                                    ->label('Fun fact (Arabic)')
                                    ->maxLength(self::MAX_FACT)
                                    ->rows(2)
                                    ->extraInputAttributes(['dir' => 'rtl']),
                            ]),
                            Tab::make('English')->schema([
                                Textarea::make('explanation.en')
                                    ->label('Fun fact (English)')
                                    ->maxLength(self::MAX_FACT)
                                    ->rows(2),
                            ]),
                        ])->columnSpanFull(),
                    ]),
            ]);
    }

    /** The type a form state names (it is an enum or its value). */
    private static function typeOf(mixed $value): QuestionType
    {
        return $value instanceof QuestionType ? $value : (QuestionType::tryFrom((string) $value) ?? QuestionType::MultipleChoice);
    }

    /** Validation rule for the options repeater. */
    public static function exactlyOneCorrect(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $correct = count(array_filter((array) $value, fn ($option) => ! empty($option['is_correct'])));

            if ($correct !== 1) {
                $fail(__('Mark exactly one option as correct.'));
            }
        };
    }

    /** Validation rule for the picture: what was uploaded must really be a picture we accept (checked by its bytes, not its name or the type the browser claims). */
    public static function realPicture(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            foreach (array_filter(Arr::wrap($value), fn ($file) => $file instanceof TemporaryUploadedFile) as $file) {
                try {
                    app(QuestionImage::class)->validate((string) $file->get());
                } catch (QuestionImageException $exception) {
                    $fail($exception->getMessage());
                }
            }
        };
    }
}
