<?php

namespace App\Models;

use App\Models\Concerns\PrunesBlankTranslations;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Translatable\Attributes\Translatable;
use Spatie\Translatable\HasTranslations;

/**
 * A category is top-level (no parent) or a subcategory of one top-level category: one level only. A question belongs to exactly one
 * category, either a top-level one (general) or a subcategory. Choosing a top-level category for a room includes its active subcategories.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name Translated (ar/en)
 * @property string $slug
 * @property string|null $icon
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['parent_id', 'name', 'slug', 'icon', 'is_active', 'sort_order'])]
#[Translatable('name')]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasTranslations, PrunesBlankTranslations;

    protected static function booted(): void
    {
        // The last line of defence for the one-level rule; the admin form checks it earlier with the same message.
        static::saving(function (Category $category) {
            if ($problem = self::parentProblem($category->parent_id, $category->exists ? $category : null)) {
                throw ValidationException::withMessages(['parent_id' => $problem]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Why this category cannot sit under that parent, or null when it can: the parent must be a top-level category, and a category that
     * already has subcategories (or is the parent itself) cannot become one.
     */
    public static function parentProblem(?int $parentId, ?self $category = null): ?string
    {
        if ($parentId === null) {
            return null;
        }
        if ($category !== null && $category->getKey() === $parentId) {
            return __('A category cannot be its own parent.');
        }

        $parent = self::query()->find($parentId);
        if ($parent === null || $parent->parent_id !== null) {
            return __('A subcategory can only sit under a top-level category.');
        }
        if ($category !== null && $category->exists && $category->children()->exists()) {
            return __('A category with subcategories cannot become a subcategory.');
        }

        return null;
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Question, $this> Questions filed directly under this category (not those of its subcategories). */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    /** @return HasManyThrough<RoomQuestion, Question, $this> The times a question of this category was asked in a room. */
    public function roomQuestions(): HasManyThrough
    {
        return $this->hasManyThrough(RoomQuestion::class, Question::class);
    }

    /** @param Builder<Category> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<Category> $query */
    public function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('categories.parent_id');
    }

    public function isSubcategory(): bool
    {
        return $this->parent_id !== null;
    }

    /** The slug the tile colour comes from: a subcategory wears its parent's tint. */
    public function tintSlug(): string
    {
        return $this->parent_id === null ? $this->slug : $this->parent->slug;
    }

    /** "Sports · Football" for a subcategory, the plain name for a top-level category, in the given language (default: the current one). */
    public function fullName(?string $locale = null): string
    {
        $name = $locale ? $this->getTranslation('name', $locale) : $this->name;

        if (! $this->isSubcategory()) {
            return $name;
        }

        return ($locale ? $this->parent->getTranslation('name', $locale) : $this->parent->name).' · '.$name;
    }
}
