<?php

namespace App\Models;

use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Game\QuestionImage;
use App\Models\Concerns\PrunesBlankTranslations;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Translatable\Attributes\Translatable;
use Spatie\Translatable\HasTranslations;

/**
 * @property int $id
 * @property int $category_id
 * @property string $text Translated (ar/en)
 * @property Difficulty $difficulty
 * @property QuestionType $type
 * @property string|null $image_path On the question_images disk (App\Game\QuestionImage). A question with a picture is a "picture question", whatever its type
 * @property float|null $answer_number Closest number: the correct value
 * @property float|null $tolerance Closest number: how far a guess may be and still score (never negative, never 0)
 * @property string|null $explanation Translated (ar/en): the optional fun fact shown with the answer
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['category_id', 'text', 'difficulty', 'type', 'image_path', 'answer_number', 'tolerance', 'explanation', 'is_active'])]
#[Translatable('text', 'explanation')]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory, HasTranslations, PrunesBlankTranslations, SoftDeletes;

    /** The tolerance a closest-number question gets when the admin leaves it empty: a quarter of the answer, but at least 1. */
    public const DEFAULT_TOLERANCE_SHARE = 0.25;

    /** @var array<string, mixed> What a question is until said otherwise (the column's default too), so a new instance already knows its type. */
    protected $attributes = ['type' => 'multiple_choice'];

    protected static function booted(): void
    {
        // A closest-number question always has a tolerance, so the scoring never divides by nothing.
        static::saving(function (Question $question) {
            if ($question->type === QuestionType::ClosestNumber && $question->answer_number !== null && ($question->tolerance === null || $question->tolerance <= 0)) {
                $question->tolerance = self::defaultTolerance($question->answer_number);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'difficulty' => Difficulty::class,
            'type' => QuestionType::class,
            'answer_number' => 'float',
            'tolerance' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /** A quarter of the answer, at least 1. */
    public static function defaultTolerance(float $answer): float
    {
        return max(1.0, round(abs($answer) * self::DEFAULT_TOLERANCE_SHARE, 4));
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<QuestionOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<RoomQuestion, $this> The times this question was asked in a room. */
    public function roomQuestions(): HasMany
    {
        return $this->hasMany(RoomQuestion::class);
    }

    /** @return HasManyThrough<PlayerAnswer, RoomQuestion, $this> Every answer players gave to it. */
    public function answers(): HasManyThrough
    {
        return $this->hasManyThrough(PlayerAnswer::class, RoomQuestion::class, 'question_id', 'room_question_id');
    }

    /** @return HasOne<QuestionOption, $this> */
    public function correctOption(): HasOne
    {
        return $this->hasOne(QuestionOption::class)->where('is_correct', true);
    }

    /** @param Builder<Question> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** A question with a picture above its options (or above its number box). */
    public function hasPicture(): bool
    {
        return filled($this->image_path);
    }

    /** The picture's address, root-relative, or null. */
    public function imageUrl(): ?string
    {
        return $this->hasPicture() ? app(QuestionImage::class)->url((string) $this->image_path) : null;
    }

    /**
     * The order the options are shown in for one game, fixed once when the question is drawn (room_questions.option_order): a multiple-choice question is
     * shuffled, a true/false one is always True then False (so True is the A tile everywhere), a closest-number one has none. Load `options` first.
     *
     * @return list<int>
     */
    public function newOptionOrder(): array
    {
        $ids = array_values($this->options->pluck('id')->map(fn ($id) => (int) $id)->all());

        return match ($this->type) {
            QuestionType::MultipleChoice => array_values(collect($ids)->shuffle()->all()),
            QuestionType::TrueFalse => $ids,
            QuestionType::ClosestNumber => [],
        };
    }

    /**
     * Playable in every given locale: the question text is non-empty in each locale and, for a question with options, there is at least one option and
     * every option text is non-empty in each locale. (A closest-number question has no options: its text is all there is to translate.)
     *
     * @param  Builder<Question>  $query
     * @param  list<string>  $locales
     */
    public function scopeTranslatedIn(Builder $query, array $locales): void
    {
        $query
            ->where(function (Builder $q) use ($locales) {
                foreach ($locales as $locale) {
                    $q->where("questions.text->{$locale}", '<>', ''); // absent key => NULL => excluded
                }
            })
            ->where(fn (Builder $q) => $q
                ->where('questions.type', QuestionType::ClosestNumber->value)
                ->orWhere(fn (Builder $withOptions) => $withOptions
                    ->whereHas('options')
                    ->whereDoesntHave('options', function (Builder $options) use ($locales) {
                        $options->where(function (Builder $q) use ($locales) {
                            foreach ($locales as $locale) {
                                $q->orWhereNull("text->{$locale}")->orWhere("text->{$locale}", '');
                            }
                        });
                    })));
    }

    /**
     * Complete for its type: a true/false question has exactly two options, one of them correct; a closest-number question has a number and a tolerance
     * above 0 and no options; a multiple-choice question is left as it always was. (The picture's file is checked by problems(), not here: a database cannot
     * look at a disk.)
     *
     * @param  Builder<Question>  $query
     */
    public function scopeCompleteForType(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('questions.type', QuestionType::MultipleChoice->value)
            ->orWhere(fn (Builder $trueFalse) => $trueFalse
                ->where('questions.type', QuestionType::TrueFalse->value)
                ->has('options', '=', 2)
                ->whereHas('options', fn (Builder $options) => $options->where('is_correct', true), '=', 1))
            ->orWhere(fn (Builder $number) => $number
                ->where('questions.type', QuestionType::ClosestNumber->value)
                ->whereNotNull('questions.answer_number')
                ->where('questions.tolerance', '>', 0)
                ->whereDoesntHave('options')));
    }

    /**
     * What a game may draw: translated in every locale AND complete for its type. This is the one definition of "playable" (the pool, the content report
     * and the admin's counts use it).
     *
     * @param  Builder<Question>  $query
     * @param  list<string>  $locales
     */
    public function scopePlayable(Builder $query, array $locales): void
    {
        $query->translatedIn($locales)->completeForType();
    }

    /**
     * In-memory twin of scopeTranslatedIn() for a loaded record (load `options` first).
     *
     * @param  list<string>  $locales
     */
    public function isTranslatedIn(array $locales): bool
    {
        $models = $this->type->hasOptions() ? [$this, ...$this->options] : [$this];
        if ($this->type->hasOptions() && $this->options->isEmpty()) {
            return false;
        }

        foreach ($models as $model) {
            $translations = $model->getTranslations('text');
            foreach ($locales as $locale) {
                if (blank($translations[$locale] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Everything that is wrong with this question for its type, in plain English; empty when it is complete (load `options` first). The admin shows it, and
     * the import and the seeder never produce a question that has any.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        $options = $this->options;

        switch ($this->type) {
            case QuestionType::MultipleChoice:
                if ($options->count() < 4 || $options->count() > 5) {
                    $problems[] = 'A multiple-choice question needs 4 or 5 options.';
                }
                if ($options->where('is_correct', true)->count() !== 1) {
                    $problems[] = 'Exactly one option must be correct.';
                }
                break;
            case QuestionType::TrueFalse:
                if ($options->count() !== 2) {
                    $problems[] = 'A true/false question needs exactly two options, True and False.';
                }
                if ($options->where('is_correct', true)->count() !== 1) {
                    $problems[] = 'Exactly one of True and False must be correct.';
                }
                break;
            case QuestionType::ClosestNumber:
                if ($this->answer_number === null) {
                    $problems[] = 'A closest-number question needs the correct number.';
                }
                if ($this->tolerance === null || $this->tolerance <= 0) {
                    $problems[] = 'A closest-number question needs a tolerance above 0.';
                }
                if ($options->isNotEmpty()) {
                    $problems[] = 'A closest-number question has no options.';
                }
                break;
        }

        if ($this->hasPicture() && ! app(QuestionImage::class)->exists($this->image_path)) {
            $problems[] = 'The picture file is missing.';
        }
        if (! $this->isTranslatedIn(['ar', 'en'])) {
            $problems[] = 'It is not translated into both Arabic and English.';
        }

        return $problems;
    }

    /**
     * Make this a true/false question with the two fixed options, True first, and say which is correct. Safe to call again (it rewrites the options).
     */
    public function syncTrueFalse(bool $answer): void
    {
        $this->options()->delete();
        foreach ([[true, 'True', 'صحيح'], [false, 'False', 'خطأ']] as $position => [$value, $en, $ar]) {
            $this->options()->create(['text' => ['ar' => $ar, 'en' => $en], 'is_correct' => $value === $answer, 'sort_order' => $position + 1]);
        }
    }
}
