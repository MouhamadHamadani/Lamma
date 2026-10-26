<?php

namespace App\Game;

use App\Models\Category;
use Illuminate\Support\Collection;

/**
 * Reading a room's category ids: a top-level id is "all of it", subcategory ids alone are "only these". Used by the create-room summary,
 * the lobby chip and the saved games, so they all say the same thing the same way.
 */
final class CategorySelection
{
    public const ALL = 'all';

    public const PARTIAL = 'partial';

    public const OFF = 'off';

    /**
     * Whether a top-level category is fully chosen, partly (some of its subcategories) or not at all.
     *
     * @param  list<int>  $ids
     * @return self::ALL|self::PARTIAL|self::OFF
     */
    public static function state(Category $category, array $ids): string
    {
        if (in_array($category->id, $ids, true)) {
            return self::ALL;
        }

        return $category->children->contains(fn (Category $child) => in_array($child->id, $ids, true)) ? self::PARTIAL : self::OFF;
    }

    /**
     * The same selection without the redundant ids: a subcategory whose parent is chosen as a whole adds nothing.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function normalize(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $redundant = Category::query()->whereIn('id', $ids)->whereIn('parent_id', $ids)->pluck('id')->all();

        return array_values(array_diff($ids, $redundant));
    }

    /**
     * "Sports: Football, Basketball · Science": a whole category by its name, a partly chosen one as "Parent: Child, Child".
     *
     * @param  list<int>  $ids
     */
    public static function label(array $ids, ?string $locale = null): string
    {
        $name = fn (Category $category) => $locale ? $category->getTranslation('name', $locale) : $category->name;

        /** @var Collection<int, Category> $chosen */
        $chosen = Category::query()->with('parent')->whereIn('id', $ids)->get();
        $whole = $chosen->whereNull('parent_id')->keyBy('id');
        $parts = $whole->map(fn (Category $category) => [$category, $name($category)]);

        foreach ($chosen->whereNotNull('parent_id')->reject(fn (Category $child) => $whole->has($child->parent_id))->groupBy('parent_id') as $children) {
            /** @var Category $parent */
            $parent = $children->first()->parent;
            $parts->push([$parent, $name($parent).': '.$children->sortBy([['sort_order', 'asc'], ['id', 'asc']])->map($name)->join(', ')]);
        }

        return $parts->sortBy(fn (array $part) => sprintf('%08d-%08d', $part[0]->sort_order, $part[0]->id))->map(fn (array $part) => $part[1])->join(' · ');
    }
}
