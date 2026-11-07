<?php

namespace App\Support;

use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Game\GuessNumber;
use App\Game\QuestionImage;
use App\Game\QuestionImageException;
use App\Models\Category;
use App\Models\Question;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Adds questions from a CSV file (Excel: Save As > CSV UTF-8). One row per question, with a header row:
 *
 *   category, difficulty, question_ar, question_en, correct_ar, correct_en, wrong1_ar, wrong1_en, wrong2_ar, wrong2_en, wrong3_ar, wrong3_en
 *   (optional: wrong4_ar, wrong4_en for a fifth option, and active = 0 to import a question switched off)
 *
 * Phase 13 adds optional columns. A file with a `type` column needs only category, difficulty, question_ar and question_en in its header, and the options
 * columns only for its multiple-choice rows; a file without one is read as before (every column of the first line above is needed), so old files keep working.
 *
 *   type            multiple_choice (the default when empty), true_false or closest_number
 *   answer          true_false: true or false (also yes/no, 1/0, صحيح/خطأ); closest_number: the correct number (Arabic digits and separators are read too)
 *   tolerance       closest_number: how far a guess may be and still score; empty = a quarter of the answer, at least 1
 *   image           the file name of a picture (a JPEG, PNG or WebP) that was uploaded together with the CSV; any type may have one
 *   explanation_ar, explanation_en   the fun fact shown with the answer
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

    /** @var list<string> What a file with a `type` column always needs. */
    public const BASE = ['category', 'difficulty', 'question_ar', 'question_en'];

    /** @var list<string> The header of the template. */
    public const TEMPLATE = [...self::REQUIRED, 'type', 'answer', 'tolerance', 'image', 'explanation_ar', 'explanation_en'];

    private const TRUE_WORDS = ['true', 't', 'yes', 'y', '1', 'صحيح', 'صح', 'نعم'];

    private const FALSE_WORDS = ['false', 'f', 'no', 'n', '0', 'خطأ', 'خطا', 'غلط', 'لا'];

    /** @var Collection<int, Category> */
    private Collection $categories;

    /** @var array<string, true> English texts already in the database or earlier in the file */
    private array $known = [];

    /** @var array<string, string> the uploaded pictures: lower-case file name => path */
    private array $images = [];

    /**
     * @param  list<string>  $images  paths of the pictures uploaded with the file; a row names one by its file name
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function import(string $path, array $images = []): array
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
            $missing = array_values(array_diff(in_array('type', $columns, true) ? self::BASE : self::REQUIRED, $columns));
            if ($missing !== []) {
                return ['imported' => 0, 'skipped' => 0, 'errors' => ['The first row must name these columns: '.implode(', ', $missing).'.']];
            }

            $this->categories = Category::query()->with('parent')->get();
            $this->known = Question::withTrashed()->get()->mapWithKeys(fn (Question $question) => [$this->key($question->getTranslation('text', 'en', false)) => true])->all();
            $this->images = [];
            foreach ($images as $image) {
                $this->images[mb_strtolower(basename($image))] = $image;
            }

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

        $type = $this->type($row['type'] ?? '');
        if ($type === null) {
            $problems[] = 'The type must be multiple_choice, true_false or closest_number.';
        }

        $text = ['ar' => $row['question_ar'] ?? '', 'en' => $row['question_en'] ?? ''];
        if ($text['ar'] === '' && $text['en'] === '') {
            $problems[] = 'The question needs a text in Arabic or English.';
        } elseif (max(mb_strlen($text['ar']), mb_strlen($text['en'])) > 500) {
            $problems[] = 'The question text is longer than 500 characters.';
        }

        $options = [];
        $truth = null;
        $number = null;
        $tolerance = null;
        if ($type === QuestionType::TrueFalse) {
            $truth = $this->truth($row['answer'] ?? '');
            if ($truth === null) {
                $problems[] = 'A true/false question needs answer = true or false.';
            }
        } elseif ($type === QuestionType::ClosestNumber) {
            $number = GuessNumber::parse($row['answer'] ?? '');
            if ($number === null) {
                $problems[] = 'A closest-number question needs a number in the answer column.';
            }
            if (($row['tolerance'] ?? '') !== '') {
                $tolerance = GuessNumber::parse($row['tolerance']);
                if ($tolerance === null || $tolerance <= 0) {
                    $problems[] = 'The tolerance must be a number above 0.';
                    $tolerance = null;
                }
            }
        } elseif ($type !== null) {
            [$options, $optionProblems] = $this->options($row);
            $problems = [...$problems, ...$optionProblems];
        }

        $explanation = ['ar' => $row['explanation_ar'] ?? '', 'en' => $row['explanation_en'] ?? ''];
        if (max(mb_strlen($explanation['ar']), mb_strlen($explanation['en'])) > 300) {
            $problems[] = 'The fun fact is longer than 300 characters.';
        }

        $picture = $row['image'] ?? '';
        if ($picture !== '' && ! isset($this->images[mb_strtolower(basename($picture))])) {
            $problems[] = "The picture \"{$picture}\" was not uploaded with the file.";
        }

        $key = $this->key($text['en']);
        if ($text['en'] !== '' && isset($this->known[$key])) {
            $problems[] = 'A question with this English text already exists.';
        }

        if ($problems !== []) {
            return $problems;
        }

        // The picture last: a row that is wrong in any other way never stores one.
        $imagePath = null;
        if ($picture !== '') {
            try {
                $imagePath = app(QuestionImage::class)->store((string) file_get_contents($this->images[mb_strtolower(basename($picture))]));
            } catch (QuestionImageException $exception) {
                return ["The picture \"{$picture}\": ".$exception->getMessage()];
            }
        }

        /** @var Category $category */
        try {
            DB::transaction(function () use ($category, $difficulty, $type, $text, $options, $truth, $number, $tolerance, $explanation, $imagePath, $row) {
                $question = $category->questions()->create([
                    'text' => $text,
                    'difficulty' => $difficulty,
                    'type' => $type,
                    'answer_number' => $number,
                    'tolerance' => $tolerance,
                    'explanation' => $explanation,
                    'image_path' => $imagePath,
                    'is_active' => ! in_array(mb_strtolower($row['active'] ?? ''), ['0', 'no', 'false', 'off', 'inactive'], true),
                ]);
                if ($truth !== null) {
                    $question->syncTrueFalse($truth);
                }
                foreach ($options as $position => $option) {
                    $question->options()->create(['text' => ['ar' => $option['ar'], 'en' => $option['en']], 'is_correct' => $option['correct'], 'sort_order' => $position + 1]);
                }
            });
        } catch (Throwable $exception) {
            app(QuestionImage::class)->delete($imagePath);

            throw $exception;
        }
        $this->known[$key] = true;

        return [];
    }

    /**
     * The options of a multiple-choice row: the correct one first, then the wrong ones.
     *
     * @param  array<string, string>  $row
     * @return array{0: list<array{ar: string, en: string, correct: bool}>, 1: list<string>} the options and the problems with them
     */
    private function options(array $row): array
    {
        $problems = [];
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

        return [$options, $problems];
    }

    /** The type a cell names; empty is multiple choice; null when it names none. */
    private function type(string $value): ?QuestionType
    {
        return match (str_replace([' ', '-', '/'], '_', mb_strtolower(trim($value)))) {
            '', 'multiple_choice', 'choice', 'mc' => QuestionType::MultipleChoice,
            'true_false', 'truefalse', 'tf', 'boolean' => QuestionType::TrueFalse,
            'closest_number', 'closest', 'number' => QuestionType::ClosestNumber,
            default => null,
        };
    }

    /** True or false from a cell, or null when it is neither. */
    private function truth(string $value): ?bool
    {
        $value = mb_strtolower(trim($value));

        return in_array($value, self::TRUE_WORDS, true) ? true : (in_array($value, self::FALSE_WORDS, true) ? false : null);
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

    /** A ready-to-fill file: the header and one example row of each type (a multiple-choice one, a true/false one and a closest-number one). */
    public static function template(): string
    {
        $blank = array_fill(0, 12, '');
        $rows = [
            self::TEMPLATE,
            ['Sports > Football', 'easy', 'كم لاعباً في الفريق الواحد؟', 'How many players in a football team on the pitch?', '11', '11', '9', '9', '10', '10', '12', '12', 'multiple_choice', '', '', '', '', ''],
            ['Sports > Football', 'easy', 'تُلعب مباراة كرة القدم بكرة واحدة.', 'A football match is played with one ball on the pitch.', ...array_slice($blank, 4), 'true_false', 'true', '', '', 'تستخدم المباراة كرة واحدة في كل لحظة.', 'Only one ball is in play at a time.'],
            ['Sports > Football', 'medium', 'كم دقيقة تدوم المباراة الرسمية من دون وقت إضافي؟', 'How many minutes does an official match last, without extra time?', ...array_slice($blank, 4), 'closest_number', '90', '', '', 'شوطان، مدة كل منهما 45 دقيقة.', 'Two halves of 45 minutes.'],
        ];

        $handle = fopen('php://temp', 'r+') ?: throw new RuntimeException('No temporary stream.');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        rewind($handle);

        return "\xEF\xBB\xBF".stream_get_contents($handle);
    }
}
