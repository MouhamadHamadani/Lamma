<?php

namespace App\Console\Commands;

use App\Game\QuestionReport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lamma:questions-report')]
#[Description('Table of playable questions per category, subcategory and difficulty, and per top-level category; flags anything under its target (exit code 1)')]
class QuestionsReportCommand extends Command
{
    public function handle(QuestionReport $report): int
    {
        $rows = $report->rows();
        $target = fn (string $kind, string $field) => (int) config("lamma.content.{$kind}.{$field}", 0);

        $this->table(
            ['Category', 'Subcategory', 'Easy', 'Medium', 'Hard', 'Total', 'Target'],
            array_map(function (array $row) {
                $cell = fn (string $column, int $count) => in_array($column, $row['short'], true) ? "<fg=red>{$count} !</>" : (string) $count;

                return [
                    $row['parent'] ? $row['parent']->getTranslation('name', 'en') : $row['category']->getTranslation('name', 'en').($row['category']->children->isNotEmpty() ? ' (direct)' : ''),
                    $row['parent'] ? $row['category']->getTranslation('name', 'en') : '',
                    $cell('easy', $row['counts']['easy']),
                    $cell('medium', $row['counts']['medium']),
                    $cell('hard', $row['counts']['hard']),
                    $cell('total', $row['total']),
                    $this->targetLabel($row['target']),
                ];
            }, $rows),
        );

        $tops = $report->topLevelTotals();
        $this->newLine();
        $this->line('Per top-level category, subcategories included (what choosing the whole category gives a host):');
        $this->table(
            ['Category', 'Easy', 'Medium', 'Hard', 'Total'],
            [...array_map(fn (array $top) => [$top['category']->getTranslation('name', 'en'), $top['counts']['easy'], $top['counts']['medium'], $top['counts']['hard'], $top['total']], $tops)],
        );

        $total = array_sum(array_column($rows, 'total'));
        $this->line("{$total} playable questions. Targets: a subcategory {$target('subcategory', 'total')} (at least {$target('subcategory', 'each')} per difficulty, {$target('subcategory', 'hard')} hard), a category without subcategories {$target('category', 'total')} (at least {$target('category', 'each')} per difficulty, {$target('category', 'hard')} hard), a category with subcategories {$target('parent', 'hard')} hard of its own.");

        $short = array_filter($rows, fn (array $row) => $row['short'] !== []);
        if ($short === []) {
            $this->info('Every category meets its target.');

            return self::SUCCESS;
        }

        foreach ($short as $row) {
            $this->error(($row['parent'] ? $row['parent']->getTranslation('name', 'en').' > ' : '').$row['category']->getTranslation('name', 'en').' is under its target: '.implode(', ', $row['short']).'.');
        }

        return self::FAILURE;
    }

    /** @param array{total: int, each: int, hard: int}|null $target */
    private function targetLabel(?array $target): string
    {
        if ($target === null) {
            return 'none';
        }

        return $target['total'] > 0
            ? "{$target['total']} (min {$target['each']} each".($target['hard'] > $target['each'] ? ", {$target['hard']} hard" : '').')'
            : "{$target['hard']} hard";
    }
}
