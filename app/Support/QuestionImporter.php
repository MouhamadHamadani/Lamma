<?php

namespace App\Support;

use App\Enums\Difficulty;
use App\Models\Category;
use App\Models\Question;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds questions from a CSV file (Excel: Save As > CSV UTF-8). One row per question, with a header row:
 *
 *   category, difficulty, question_ar, question_en, correct_ar, correct_en, wrong1_ar, wrong1_en, wrong2_ar, wrong2_en, wrong3_ar, wrong3_en
 *   (optional: wrong4_ar, wrong4_en for a fifth option, and active = 0 to import a question switched off)
 *
 * `category` is a top-level category ("Sports"), a subcategory with its parent ("Sports > Football"), or just a subcategory name when that is
 * unambiguous. Each part can be the slug or the name in either language, in any letter case. A row with a problem is skipped and
 * reported with its line number; the rows that are fine are still imported. A question whose English text already exists is skipped.
 */
final class QuestionImporter
{
    public const MAX_ROWS = 2000;

    /** @var list<string> */
    public const REQUIRED = ['category', 'difficulty', 'question_ar', 'question_en', 'correct_ar', 'correct_en', 'wrong1_ar', 'wrong1_en', 'wrong2_ar', 'wrong2_en', 'wrong3_ar', 'wrong3_en'];

    /** @var Collection<int, Category> */
    private Collection $categories;

    /** @var array<string, true> English texts already in the database or earlier in the file */
    private array $known = [];

    /**
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function import(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['The file could not be read.']];
        }

        try {
            $first = fgets($handle);
            $delimiter = $this->delimiter((string) $first);
            rewind($handle);

            $header = fgetcsv($handle, 0, $delimiter, '"', '');
            $columns = is_array($header) ? array_map(fn ($name) => $this->normalizeHeader((string) $name), $header) : [];
            $missing = array_values(array_diff(self::REQUIRED, $columns));
            if ($missing !== []) {
                return ['imported' => 0, 'skipped' => 0, 'errors' => ['The first row must name these columns: '.implode(', ', $missing).'.']];
            }

            $this->categories = Category::query()->with('parent')->get();
            $this->known = Question::withTrashed()->get()->mapWithKeys(fn (Question $question) => [$this->key($question->getTranslation('text', 'en', false)) => true])->all();

            $imported = $skipped = 0;
            $errors = [];
            for ($line = 2; ($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false; $line++) {
                if ($row === [null] || trim(implode('', $row)) === '') {
                    continue;
                }
                if ($imported + $skipped >= self::MAX_ROWS) {
                    $errors[] = 'Stopped at '.self::MAX_ROWS.' rows: import the rest in another file.';
                    break;
                }

                $problems = $this->importRow(array_combine($columns, array_pad(array_map(fn ($cell) => trim((string) $cell), $row), count($columns), '')) ?: []);
                if ($problems === []) {
                    $imported++;
                } else {
                    $skipped++;
                    $errors[] = "Row {$line}: ".implode(' ', $problems);
                }
            }

            return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
        } finally {
            fclose($handle);
        }
    }

    /**
     * One row: create the question, or say what is wrong with it.
     *
     * @param  array<string, string>  $row
     * @return list<string> the problems; empty when the question was created
     */
    private function importRow(array $row): array
    {
        $problems = [];

        $category = $this->category($row['category'] ?? '');
        if (is_string($category)) {
            $problems[] = $category;
        }

        $difficulty = Difficulty::tryFrom(mb_strtolower($row['difficulty'] ?? ''));
        if ($difficulty === null) {
            $problems[] = 'The difficulty must be easy, medium or hard.';
        }

        $text = ['ar' => $row['question_ar'] ?? '', 'en' => $row['question_en'] ?? ''];
        if ($text['ar'] === '' && $text['en'] === '') {
            $problems[] = 'The question needs a text in Arabic or English.';
        } elseif (max(mb_strlen($text['ar']), mb_strlen($text['en'])) > 500) {
            $problems[] = 'The question text is longer than 500 characters.';
        }

        $options = [['ar' => $row['correct_ar'] ?? '', 'en' => $row['correct_en'] ?? '', 'correct' => true]];
        foreach ([1, 2, 3, 4] as $n) {
            $option = ['ar' => $row["wrong{$n}_ar"] ?? '', 'en' => $row["wrong{$n}_en"] ?? '', 'correct' => false];
            if ($option['ar'] !== '' || $option['en'] !== '') {
                $options[] = $option;
            }
        }
        if ($options[0]['ar'] === '' && $options[0]['en'] === '') {
            $problems[] = 'The correct answer is empty.';
        } elseif (count($options) < 4) {
            $problems[] = 'It needs 4 or 5 options: the correct one and 3 or 4 wrong ones.';
        }
        foreach ($options as $option) {
            if (max(mb_strlen($option['ar']), mb_strlen($option['en'])) > 255) {
                $problems[] = 'An option is longer than 255 characters.';
                break;
            }
        }

        $key = $this->key($text['en']);
        if ($text['en'] !== '' && isset($this->known[$key])) {
            $problems[] = 'A question with this English text already exists.';
        }

        if ($problems !== []) {
            return $problems;
        }

        /** @var Category $category */
        DB::transaction(function () use ($category, $difficulty, $text, $options, $row) {
            $question = $category->questions()->create([
                'text' => $text,
                'difficulty' => $difficulty,
                'is_active' => ! in_array(mb_strtolower($row['active'] ?? ''), ['0', 'no', 'false', 'off', 'inactive'], true),
            ]);
            foreach ($options as $position => $option) {
                $question->options()->create(['text' => ['ar' => $option['ar'], 'en' => $option['en']], 'is_correct' => $option['correct'], 'sort_order' => $position + 1]);
            }
        });
        $this->known[$key] = true;

        return [];
    }

    /** The category a cell names, or the reason it cannot be found. */
    public function category(string $value): Category|string
    {
        $categories = $this->categories ?? Category::query()->with('parent')->get();
        $parts = array_values(array_filter(array_map('trim', explode('>', $value)), fn ($part) => $part !== ''));

        if (count($parts) === 0 || count($parts) > 2) {
            return $value === '' ? 'The category is empty.' : "The category \"{$value}\" must be a category (\"Sports\") or a subcategory (\"Sports > Football\").";
        }

        if (count($parts) === 1) {
            $found = $categories->filter(fn (Category $category) => $this->named($category, $parts[0]));

            return match ($found->count()) {
                0 => "Unknown category \"{$value}\".",
                1 => $found->first(),
                default => "\"{$value}\" matches more than one category ({$found->map(fn (Category $category) => $category->fullName('en'))->join(', ')}): write the parent too, like \"Sports > Football\".",
            };
        }

        $parent = $categories->first(fn (Category $category) => $category->parent_id === null && $this->named($category, $parts[0]));
        if ($parent === null) {
            return "Unknown category \"{$parts[0]}\" in \"{$value}\".";
        }
        $child = $categories->first(fn (Category $category) => $category->parent_id === $parent->id && $this->named($category, $parts[1]));

        return $child ?? "\"{$parent->name}\" has no subcategory \"{$parts[1]}\".";
    }

    /** Whether the text is this category's slug or its name in either language, ignoring case and extra spaces. */
    private function named(Category $category, string $text): bool
    {
        $text = $this->key($text);

        return $this->key($category->slug) === $text
            || $this->key($category->getTranslation('name', 'en', false)) === $text
            || $this->key($category->getTranslation('name', 'ar', false)) === $text;
    }

    private function key(?string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $text)));
    }

    private function normalizeHeader(string $name): string
    {
        return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name));
    }

    /** Excel in many locales saves with ";" or a tab: use whichever the header line has most of. */
    private function delimiter(string $headerLine): string
    {
        $counts = [',' => substr_count($headerLine, ','), ';' => substr_count($headerLine, ';'), "\t" => substr_count($headerLine, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /** A ready-to-fill file: the header and one example row. */
    public static function template(): string
    {
        $rows = [
            self::REQUIRED,
            ['Sports > Football', 'easy', 'كم لاعباً في الفريق الواحد؟', 'How many players in a football team on the pitch?', '11', '11', '9', '9', '10', '10', '12', '12'],
        ];

        $handle = fopen('php://temp', 'r+') ?: throw new RuntimeException('No temporary stream.');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        rewind($handle);

        return "\xEF\xBB\xBF".stream_get_contents($handle);
    }
}
