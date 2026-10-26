<?php

namespace App\Game;

use App\Models\Category;
use App\Models\Question;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which questions a game may use. Every game uses only questions fully translated in BOTH Arabic and English,
 * whatever the host-screen language is, because players pick their own language.
 *
 * The room stores category ids, top-level and/or subcategory. This is the one place that turns them into questions: a top-level id
 * means all of it (its own questions and every active subcategory's), a subcategory id means only that subcategory.
 */
class QuestionPool
{
    /** @var list<string> */
    public const LOCALES = ['ar', 'en'];

    /** @return Builder<Question> Active, fully translated questions in the chosen active categories (and the difficulty, unless Mixed). */
    public function query(RoomSettings $settings): Builder
    {
        return Question::query()
            ->active()
            ->translatedIn(self::LOCALES)
            ->whereIn('category_id', $this->categories($settings->categoryIds))
            ->when($settings->difficulty, fn (Builder $query, $difficulty) => $query->where('difficulty', $difficulty));
    }

    /**
     * The active categories those ids stand for. A subcategory counts only while its parent is active too.
     *
     * @param  list<int>  $ids
     * @return Builder<Category> ids only, ready to be a sub-select
     */
    public function categories(array $ids): Builder
    {
        return Category::query()
            ->active()
            ->where(fn (Builder $query) => $query->whereIn('id', $ids)->orWhereIn('parent_id', $ids))
            ->where(fn (Builder $query) => $query->whereNull('parent_id')->orWhereHas('parent', fn (Builder $parent) => $parent->active()))
            ->select('id');
    }

    public function available(RoomSettings $settings): int
    {
        return $this->query($settings)->count();
    }

    public function isEnough(RoomSettings $settings): bool
    {
        return $this->available($settings) >= $settings->questionCount;
    }
}
