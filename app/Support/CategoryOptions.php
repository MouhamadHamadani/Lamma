<?php

namespace App\Support;

use App\Models\Category;

/** Category lists for the admin selects: grouped by parent, so a subcategory is easy to find under the category it belongs to. */
final class CategoryOptions
{
    /**
     * Every category, a group per top-level category that has subcategories (the parent first, as "Name (general)", then its subcategories),
     * and one "Categories" group for the top-level ones without subcategories.
     *
     * @return array<string, array<int, string>> group label => id => label
     */
    public static function grouped(): array
    {
        $groups = [];
        $plain = [];

        foreach (Category::query()->topLevel()->with('children')->orderBy('sort_order')->orderBy('id')->get() as $category) {
            if ($category->children->isEmpty()) {
                $plain[$category->id] = $category->name;

                continue;
            }

            $groups[$category->name] = [$category->id => $category->name.' (general)']
                + $category->children->mapWithKeys(fn (Category $child) => [$child->id => $child->name])->all();
        }

        return $plain === [] ? $groups : ['Categories' => $plain] + $groups;
    }

    /**
     * Only the subcategories, grouped by their parent.
     *
     * @return array<string, array<int, string>>
     */
    public static function subcategories(): array
    {
        return Category::query()->topLevel()->has('children')->with('children')->orderBy('sort_order')->orderBy('id')->get()
            ->mapWithKeys(fn (Category $parent) => [$parent->name => $parent->children->mapWithKeys(fn (Category $child) => [$child->id => $child->name])->all()])
            ->all();
    }
}
