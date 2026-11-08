<?php

namespace App\Game;

/**
 * Looks over the shipped question bank (the array ContentSeeder::data() builds, or any file with the same shape) for the tells that make a quiz easy to game, repetitive
 * or out of date. It reads the data files, not the database, so it runs before a deploy and an admin's edits never change its answer. Behind `lamma:questions-audit`.
 *
 *  - counts: questions per slug and difficulty.
 *  - longest: the share of multiple-choice questions whose correct option is STRICTLY the longest of the four, in English and in Arabic (a tie is not a tell).
 *    About a quarter is what chance gives; `MAX_LONGEST_SHARE` is the share above which a slug is flagged.
 *  - numeric: how many correct answers are a plain number (a lazy distractor set is easy to build around a number).
 *  - repeated: a correct answer that comes up more than `MAX_REPEATS` times in the same category or subcategory.
 *  - identical: different questions that share the very same three wrong options.
 *  - stale: question or fun-fact text with a time-sensitive word (current, latest, today, now, reigning, a year from 2023 on, ...).
 *
 * @phpstan-type Rows list<array<int, mixed>>
 */
class QuestionAudit
{
    /** A correct answer that appears more than this many times in one category is flagged. */
    public const MAX_REPEATS = 3;

    /** A slug is flagged when the correct option is strictly the longest in more than this share of its multiple-choice questions. */
    public const MAX_LONGEST_SHARE = 0.35;

    /** Words and years that make a question go out of date ("electric current" is physics, not a time claim). */
    public const TIME_SENSITIVE = '/\b((?<!electric )current|currently|latest|newest|recent|recently|nowadays|today|now|reigning|at present|this year|last year|next year|so far|as of|202[3-9]|20[3-9]\d)\b/i';

    /** @param array{questions: array<string, Rows>} $data */
    public function __construct(private readonly array $data) {}

    /** @return array<string, array{easy: int, medium: int, hard: int, total: int}> */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->data['questions'] as $slug => $rows) {
            $easy = $medium = $hard = 0;
            foreach ($rows as $row) {
                match ($row[0]) {
                    'easy' => $easy++,
                    'medium' => $medium++,
                    'hard' => $hard++,
                    default => null,
                };
            }
            $counts[$slug] = ['easy' => $easy, 'medium' => $medium, 'hard' => $hard, 'total' => count($rows)];
        }

        return $counts;
    }

    /**
     * Per slug: how many multiple-choice questions there are and in how many the correct option is strictly the longest (English, Arabic), plus how many correct
     * answers are a plain number and how many questions ask for a number (closest-number).
     *
     * @return array<string, array{choice: int, longest_en: int, longest_ar: int, share_en: float, share_ar: float, numeric: int, closest: int}>
     */
    public function tells(): array
    {
        $tells = [];
        foreach ($this->data['questions'] as $slug => $rows) {
            $row = ['choice' => 0, 'longest_en' => 0, 'longest_ar' => 0, 'share_en' => 0.0, 'share_ar' => 0.0, 'numeric' => 0, 'closest' => 0];
            foreach ($rows as $question) {
                $type = $question[4]['type'] ?? 'multiple_choice';
                if ($type === 'closest_number') {
                    $row['closest']++;
                }
                if ($type !== 'multiple_choice') {
                    continue;
                }

                $row['choice']++;
                $row['longest_en'] += $this->correctIsLongest($question[3], 1) ? 1 : 0;
                $row['longest_ar'] += $this->correctIsLongest($question[3], 0) ? 1 : 0;
                $row['numeric'] += $this->isNumber($this->part($question[3][0], 1)) ? 1 : 0;
            }
            $row['share_en'] = $row['choice'] > 0 ? $row['longest_en'] / $row['choice'] : 0.0;
            $row['share_ar'] = $row['choice'] > 0 ? $row['longest_ar'] / $row['choice'] : 0.0;
            $tells[$slug] = $row;
        }

        return $tells;
    }

    /**
     * Correct answers that come up more than MAX_REPEATS times in one slug (a sign the distractors are lazy: the same answer keeps being right).
     *
     * @return list<array{slug: string, answer: string, times: int}>
     */
    public function repeated(): array
    {
        $found = [];
        foreach ($this->data['questions'] as $slug => $rows) {
            $answers = [];
            foreach ($rows as $row) {
                if (($row[4]['type'] ?? 'multiple_choice') === 'multiple_choice') {
                    $answers[mb_strtolower(trim($this->part($row[3][0], 1)))][] = true;
                }
            }
            foreach ($answers as $answer => $uses) {
                if (count($uses) > self::MAX_REPEATS) {
                    $found[] = ['slug' => $slug, 'answer' => (string) $answer, 'times' => count($uses)];
                }
            }
        }

        return $found;
    }

    /**
     * Different questions that share exactly the same three wrong options (English, case ignored): the distractors were copied, not chosen.
     *
     * @return list<array{wrong: list<string>, questions: list<string>}>
     */
    public function identical(): array
    {
        $groups = [];
        foreach ($this->data['questions'] as $slug => $rows) {
            foreach ($rows as $row) {
                if (($row[4]['type'] ?? 'multiple_choice') !== 'multiple_choice') {
                    continue;
                }
                $wrong = array_map(fn (string $option) => mb_strtolower(trim($this->part($option, 1))), array_slice($row[3], 1));
                if (array_filter($wrong, fn (string $option) => ! $this->isNumber($option)) === []) {
                    continue;   // three plain numbers (2, 4, 5) come up for any "how many" question: not a copy
                }
                sort($wrong);
                $groups[implode('|', $wrong)]['wrong'] = $wrong;
                $groups[implode('|', $wrong)]['questions'][] = "{$slug}: {$row[2]}";
            }
        }

        return array_values(array_filter($groups, fn (array $group) => count($group['questions']) > 1));
    }

    /**
     * Questions whose English text (or fun fact) has a time-sensitive word. The options are not read: "Now" can be a right answer to a question about a dialect word.
     *
     * @return list<array{slug: string, question: string, word: string}>
     */
    public function stale(): array
    {
        $found = [];
        foreach ($this->data['questions'] as $slug => $rows) {
            foreach ($rows as $row) {
                $text = $row[2].' '.(isset($row[4]['fact']) ? $this->part($row[4]['fact'], 1) : '');
                if (preg_match(self::TIME_SENSITIVE, $text, $match) === 1) {
                    $found[] = ['slug' => $slug, 'question' => $row[2], 'word' => $match[1]];
                }
            }
        }

        return $found;
    }

    /**
     * Slugs whose strictly-longest share is over the limit, in either language (a slug with fewer than 20 multiple-choice questions is too small to say).
     *
     * @return list<string>
     */
    public function overLimit(): array
    {
        return array_keys(array_filter($this->tells(), fn (array $row) => $row['choice'] >= 20 && max($row['share_en'], $row['share_ar']) > self::MAX_LONGEST_SHARE));
    }

    /** Whether any hard defect was found (stale wording, copied wrong options). The repeats and the length share are warnings only. */
    public function hasDefects(): bool
    {
        return $this->stale() !== [] || $this->identical() !== [];
    }

    /** @param list<string> $options the correct one first, "arabic|english" each */
    private function correctIsLongest(array $options, int $language): bool
    {
        $lengths = array_map(fn (string $option) => mb_strlen($this->part($option, $language)), $options);
        $wrong = array_slice($lengths, 1);

        return $wrong !== [] && $lengths[0] > max($wrong);
    }

    /** Whether an option is a plain number ("6", "2.54", "21%", "1,000"). */
    private function isNumber(string $option): bool
    {
        return preg_match('/^[+-]?\d[\d.,]*%?$/', trim($option)) === 1;
    }

    /** One language of an "arabic|english" option (an option without a bar reads the same in both). */
    private function part(string $option, int $language): string
    {
        return str_contains($option, '|') ? explode('|', $option, 2)[$language] : $option;
    }
}
