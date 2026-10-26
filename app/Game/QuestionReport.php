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
 * for its direct questions only and has no target. `short` names every cell under its target: "easy", "medium", "hard" and/or "total".
 *
 * @phpstan-type Target array{total: int, each: int}
 * @phpstan-type Row array{category: Category, parent: Category|null, counts: array<string, int>, total: int, target: Target|null, short: list<string>}
 */
class QuestionReport
{
    /** @return list<Row> */
    public function rows(): array
    {
        $found = [];
        $query = Question::query()->active()->translatedIn(QuestionPool::LOCALES)
            ->select('category_id', 'difficulty', DB::raw('count(*) as total'))->groupBy('category_id', 'difficulty');
        foreach ($query->get() as $row) {
            $found[$row->category_id][$row->difficulty->value] = (int) $row->getAttribute('total');
        }

        $rows = [];
        $categories = Category::query()->active()->topLevel()->with(['children' => fn ($children) => $children->active()])->orderBy('sort_order')->orderBy('id')->get();

        foreach ($categories as $category) {
            $rows[] = $this->row($category, null, $found[$category->id] ?? [], $category->children->isEmpty() ? $this->target('category') : null);

            foreach ($category->children as $child) {
                $rows[] = $this->row($child, $category, $found[$child->id] ?? [], $this->target('subcategory'));
            }
        }

        return $rows;
    }

    /** Whether any active category is under its target. */
    public function anyShort(): bool
    {
        return collect($this->rows())->contains(fn (array $row) => $row['short'] !== []);
    }

    /** @return Target */
    private function target(string $kind): array
    {
        return ['total' => (int) config("lamma.content.{$kind}.total"), 'each' => (int) config("lamma.content.{$kind}.each")];
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
            $short = array_keys(array_filter($counts, fn (int $count) => $count < $target['each']));
            if ($total < $target['total']) {
                $short[] = 'total';
            }
        }

        return ['category' => $category, 'parent' => $parent, 'counts' => $counts, 'total' => $total, 'target' => $target, 'short' => $short];
    }
}
