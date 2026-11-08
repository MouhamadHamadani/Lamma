<?php

namespace App\Game;

use App\Enums\Difficulty;
use App\Models\Category;
use App\Models\Question;
use Illuminate\Support\Facades\DB;

/**
 * How many playable questions (active, translated in Arabic and English) each category has per difficulty, against the targets in
 * config/lamma.php (`content`). Behind `lamma:questions-report` and the production doctor.
 *
 * A row is a category that holds questions: a subcategory, or a top-level category. A top-level category that has subcategories is listed
 * for its direct questions only; it has no total or per-difficulty target, only the floor for its own hard tier (`content.parent.hard`, Phase 15).
 * `short` names every cell under its target: "easy", "medium", "hard" and/or "total". A target may carry a `hard` floor (Phase 15): the hard tier alone
 * must reach it, and it is checked on top of `each`.
 *
 * @phpstan-type Target array{total: int, each: int, hard: int}
 * @phpstan-type Row array{category: Category, parent: Category|null, counts: array<string, int>, total: int, target: Target|null, short: list<string>}
 */
class QuestionReport
{
    /** @return list<Row> */
    public function rows(): array
    {
        $found = [];
        $query = Question::query()->active()->playable(QuestionPool::LOCALES)
            ->select('category_id', 'difficulty', DB::raw('count(*) as total'))->groupBy('category_id', 'difficulty');
        foreach ($query->get() as $row) {
            $found[$row->category_id][$row->difficulty->value] = (int) $row->getAttribute('total');
        }

        $rows = [];
        $categories = Category::query()->active()->topLevel()->with(['children' => fn ($children) => $children->active()])->orderBy('sort_order')->orderBy('id')->get();

        foreach ($categories as $category) {
            $rows[] = $this->row($category, null, $found[$category->id] ?? [], $this->target($category->children->isEmpty() ? 'category' : 'parent'));

            foreach ($category->children as $child) {
                $rows[] = $this->row($child, $category, $found[$child->id] ?? [], $this->target('subcategory'));
            }
        }

        return $rows;
    }

    /**
     * Each top-level category with everything below it added in: its own questions plus every active subcategory's (what a host gets by choosing the whole category).
     *
     * @return list<array{category: Category, counts: array<string, int>, total: int}>
     */
    public function topLevelTotals(): array
    {
        $totals = [];
        foreach ($this->rows() as $row) {
            $top = $row['parent'] ?? $row['category'];
            $totals[$top->id] ??= ['category' => $top, 'counts' => ['easy' => 0, 'medium' => 0, 'hard' => 0], 'total' => 0];
            foreach ($row['counts'] as $difficulty => $count) {
                $totals[$top->id]['counts'][$difficulty] += $count;
            }
            $totals[$top->id]['total'] += $row['total'];
        }

        return array_values($totals);
    }

    /** Whether any active category is under its target. */
    public function anyShort(): bool
    {
        return collect($this->rows())->contains(fn (array $row) => $row['short'] !== []);
    }

    /**
     * The target of a kind of category (`subcategory`, `category`, or `parent` for one that has subcategories), or null when config/lamma.php has none.
     *
     * @return Target|null
     */
    private function target(string $kind): ?array
    {
        $target = config("lamma.content.{$kind}");
        if (! is_array($target)) {
            return null;
        }

        return ['total' => (int) ($target['total'] ?? 0), 'each' => (int) ($target['each'] ?? 0), 'hard' => (int) ($target['hard'] ?? 0)];
    }

    /**
     * @param  array<string, int>  $found  count per difficulty value
     * @param  Target|null  $target
     * @return Row
     */
    private function row(Category $category, ?Category $parent, array $found, ?array $target): array
    {
        $counts = [];
        foreach (Difficulty::cases() as $difficulty) {
            $counts[$difficulty->value] = $found[$difficulty->value] ?? 0;
        }
        $total = array_sum($counts);

        $short = [];
        if ($target !== null) {
            $short = array_keys(array_filter($counts, fn (int $count, string $difficulty) => $count < max($target['each'], $difficulty === 'hard' ? $target['hard'] : 0), ARRAY_FILTER_USE_BOTH));
            if ($total < $target['total']) {
                $short[] = 'total';
            }
        }

        return ['category' => $category, 'parent' => $parent, 'counts' => $counts, 'total' => $total, 'target' => $target, 'short' => $short];
    }
}
