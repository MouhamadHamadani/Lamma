<?php

namespace App\Console\Commands;

use App\Game\QuestionReport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lamma:questions-report')]
#[Description('Table of playable questions per category, subcategory and difficulty; flags anything under its target (exit code 1)')]
class QuestionsReportCommand extends Command
{
    public function handle(QuestionReport $report): int
    {
        $rows = $report->rows();
        $targets = config('lamma.content');

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
                    $row['target'] ? "{$row['target']['total']} (min {$row['target']['each']} each)" : 'none',
                ];
            }, $rows),
        );

        $total = array_sum(array_column($rows, 'total'));
        $short = array_filter($rows, fn (array $row) => $row['short'] !== []);
        $this->line("{$total} playable questions. Targets: a subcategory {$targets['subcategory']['total']} (at least {$targets['subcategory']['each']} per difficulty), a category without subcategories {$targets['category']['total']} (at least {$targets['category']['each']} per difficulty).");

        if ($short === []) {
            $this->info('Every category meets its target.');

            return self::SUCCESS;
        }

        foreach ($short as $row) {
            $this->error(($row['parent'] ? $row['parent']->getTranslation('name', 'en').' > ' : '').$row['category']->getTranslation('name', 'en').' is under its target: '.implode(', ', $row['short']).'.');
        }

        return self::FAILURE;
    }
}
