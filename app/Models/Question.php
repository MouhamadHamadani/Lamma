<?php

namespace App\Models;

use App\Enums\Difficulty;
use App\Models\Concerns\PrunesBlankTranslations;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string|null $image_path
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['category_id', 'text', 'difficulty', 'image_path', 'is_active'])]
#[Translatable('text')]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory, HasTranslations, PrunesBlankTranslations, SoftDeletes;

    protected function casts(): array
    {
        return [
            'difficulty' => Difficulty::class,
            'is_active' => 'boolean',
        ];
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

    /** @return HasOne<QuestionOption, $this> */
    public function correctOption(): HasOne
    {
        return $this->hasOne(QuestionOption::class)->where('is_correct', true);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Playable in every given locale: the question text and every option text
     * is non-empty in each locale, and there is at least one option.
     *
     * @param  list<string>  $locales
     */
    public function scopeTranslatedIn(Builder $query, array $locales): void
    {
        $query
            ->where(function (Builder $q) use ($locales) {
                foreach ($locales as $locale) {
                    $q->where("text->{$locale}", '<>', ''); // absent key => NULL => excluded
                }
            })
            ->whereHas('options')
            ->whereDoesntHave('options', function (Builder $options) use ($locales) {
                $options->where(function (Builder $q) use ($locales) {
                    foreach ($locales as $locale) {
                        $q->orWhereNull("text->{$locale}")->orWhere("text->{$locale}", '');
                    }
                });
            });
    }

    /**
     * In-memory twin of scopeTranslatedIn() for a loaded record (load `options` first).
     *
     * @param  list<string>  $locales
     */
    public function isTranslatedIn(array $locales): bool
    {
        if ($this->options->isEmpty()) {
            return false;
        }

        foreach ([$this, ...$this->options] as $model) {
            $translations = $model->getTranslations('text');
            foreach ($locales as $locale) {
                if (blank($translations[$locale] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }
}
