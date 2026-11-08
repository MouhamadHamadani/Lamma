<?php

namespace App\Console\Commands;

use App\Game\QuestionAudit;
use Database\Seeders\ContentSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lamma:questions-audit {--file= : Audit a PHP file that returns the same data as ContentSeeder::data() instead of the shipped bank} {--strict : Exit with 1 when any defect is found (the default always exits 0 once the data is read)}')]
#[Description('Audits the shipped question bank: counts, how often the correct option is the longest, numeric answers, repeated answers, copied wrong options, time-sensitive wording')]
class QuestionsAuditCommand extends Command
{
    public function handle(): int
    {
        $data = $this->data();
        if ($data === null) {
            return self::FAILURE;
        }

        $audit = new QuestionAudit($data);
        $counts = $audit->counts();
        $tells = $audit->tells();
        $limit = QuestionAudit::MAX_LONGEST_SHARE;

        $this->table(
            ['Slug', 'Easy', 'Medium', 'Hard', 'Total', 'Longest EN', 'Longest AR', 'Numeric'],
            array_map(function (string $slug) use ($counts, $tells, $limit) {
                $tell = $tells[$slug];
                $share = fn (float $value) => $tell['choice'] >= 20 && $value > $limit ? '<fg=yellow>'.$this->percent($value).' !</>' : $this->percent($value);

                return [$slug, $counts[$slug]['easy'], $counts[$slug]['medium'], $counts[$slug]['hard'], $counts[$slug]['total'], $share($tell['share_en']), $share($tell['share_ar']), $tell['numeric'].($tell['closest'] > 0 ? " (+{$tell['closest']} closest)" : '')];
            }, array_keys($counts)),
        );

        $choice = array_sum(array_column($tells, 'choice'));
        $this->line(sprintf(
            '%d questions, %d multiple-choice. The correct option is strictly the longest in %s (English) and %s (Arabic) of them overall; chance gives about a quarter, a slug over %s is flagged. %d correct answers are a plain number.',
            array_sum(array_column($counts, 'total')),
            $choice,
            $this->percent($choice > 0 ? array_sum(array_column($tells, 'longest_en')) / $choice : 0),
            $this->percent($choice > 0 ? array_sum(array_column($tells, 'longest_ar')) / $choice : 0),
            $this->percent($limit),
            array_sum(array_column($tells, 'numeric')),
        ));

        $this->section('Warnings', $this->warnings($audit));
        $this->section('Defects', $this->defects($audit));

        if ($audit->hasDefects()) {
            $this->warn('Defects found (stale wording, copied wrong options). A question that is already live cannot be reworded here: its English text is its identity, so a new wording would be added as a second question. Fix the new ones; use --strict to make this an error.');

            return $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $this->info('No defects. (Warnings, if any, are for a person to look at.)');

        return self::SUCCESS;
    }

    /** @return array{questions: array<string, list<array<int, mixed>>>}|null */
    private function data(): ?array
    {
        $file = $this->option('file');
        if (! is_string($file) || $file === '') {
            /** @var array{questions: array<string, list<array<int, mixed>>>} $shipped */
            $shipped = ContentSeeder::data();

            return $shipped;
        }

        if (! is_file($file)) {
            $this->error("No such file: {$file}");

            return null;
        }
        $data = require $file;
        if (! is_array($data) || ! isset($data['questions']) || ! is_array($data['questions'])) {
            $this->error("{$file} must return an array with a 'questions' key, like ContentSeeder::data().");

            return null;
        }

        return $data;
    }

    /** @return list<string> */
    private function warnings(QuestionAudit $audit): array
    {
        $lines = [];
        foreach ($audit->overLimit() as $slug) {
            $tell = $audit->tells()[$slug];
            $lines[] = sprintf('%s: the correct option is strictly the longest in %s (English) / %s (Arabic) of its questions.', $slug, $this->percent($tell['share_en']), $this->percent($tell['share_ar']));
        }
        foreach ($audit->repeated() as $row) {
            $lines[] = "{$row['slug']}: \"{$row['answer']}\" is the correct answer {$row['times']} times (more than ".QuestionAudit::MAX_REPEATS.').';
        }

        return $lines;
    }

    /** @return list<string> */
    private function defects(QuestionAudit $audit): array
    {
        $lines = [];
        foreach ($audit->identical() as $group) {
            $lines[] = 'Same three wrong options ('.implode(', ', $group['wrong']).') in: '.implode(' / ', $group['questions']);
        }
        foreach ($audit->stale() as $row) {
            $lines[] = "{$row['slug']}: time-sensitive word \"{$row['word']}\" in \"{$row['question']}\"";
        }

        return $lines;
    }

    /** @param list<string> $lines */
    private function section(string $title, array $lines): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</> (".count($lines).')');
        foreach ($lines as $line) {
            $this->line("  - {$line}");
        }
    }

    private function percent(float|int $value): string
    {
        return round($value * 100).'%';
    }
}
