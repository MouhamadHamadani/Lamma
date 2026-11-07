<?php

namespace Database\Seeders;

use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Game\QuestionImage;
use App\Models\Category;
use App\Models\Question;
use App\Support\SeedKey;
use Database\Seeders\Concerns\ReportsToConsole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The categories and questions Lamma ships with. Safe to run in production, and on every deploy: it only ADDS what is missing and never
 * changes what exists, so admin edits survive.
 *
 *  - A category is found by its slug. A subcategory names its parent (`'parent' => 'sports'`) and is created under it.
 *  - A question is found by its stable key (`questions.seed_key`, a hash of the English text, see App\Support\SeedKey), soft-deleted ones
 *    included, so a question an admin removed does not come back and one whose text an admin edited is not added a second time. A question
 *    an admin wrote by hand counts by its English text.
 *
 * The data: database/seeders/data/questions.php (the categories and the first 400 questions) plus one file per category or subcategory in
 * database/seeders/data/questions/<slug>.php (a list of questions), plus the packs in database/seeders/data/packs/*.php (each a map of category slug => list of
 * questions: the variety pack has the true/false, closest-number and picture questions), merged by data() below.
 *
 * A question is [difficulty, arabic, english, answer] and, for the kinds below, a fifth element with the extras. The answer of a multiple-choice question is
 * its four options (the correct one first); of a true/false question true or false; of a closest-number question the number. The extras:
 * ['type' => 'true_false' | 'closest_number', 'tolerance' => 10, 'image' => 'flag-japan.svg', 'fact' => 'arabic|english']. A row without extras is the multiple-choice
 * question it always was. An image is one of our own drawings in database/seeders/data/images, copied to the pictures disk under seed/.
 */
class ContentSeeder extends Seeder
{
    use ReportsToConsole;

    /**
     * @return array{categories: array<string, array{ar: string, en: string, icon: string, parent?: string}>, questions: array<string, list<array<int, mixed>>>}
     */
    public static function data(): array
    {
        // (__DIR__, not database_path(): the tests read the data before the app has booted)
        $data = require __DIR__.'/data/questions.php';

        $files = glob(__DIR__.'/data/questions/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $slug = basename($file, '.php');
            $data['questions'][$slug] = [...($data['questions'][$slug] ?? []), ...require $file];
        }

        $packs = glob(__DIR__.'/data/packs/*.php') ?: [];
        sort($packs);
        foreach ($packs as $pack) {
            foreach (require $pack as $slug => $rows) {
                $data['questions'][$slug] = [...($data['questions'][$slug] ?? []), ...$rows];
            }
        }

        return $data;
    }

    /** @var array<string, mixed>|null data to seed instead of the shipped files (tests) */
    private ?array $given = null;

    /**
     * Seed this data instead of the shipped files (same shape as data()). For tests.
     *
     * @param  array<string, mixed>  $data
     */
    public function withData(array $data): static
    {
        $this->given = $data;

        return $this;
    }

    public function run(): void
    {
        $data = $this->given ?? self::data();

        $added = DB::transaction(function () use ($data) {
            $categories = $this->categories($data['categories']);
            $known = $this->knownKeys();
            $added = 0;

            foreach ($data['questions'] as $slug => $questions) {
                $category = $categories[$slug] ?? Category::query()->where('slug', $slug)->firstOrFail();

                foreach ($questions as $index => $row) {
                    [$difficulty, $ar, $en, $answer] = $row;
                    $key = SeedKey::for($en);
                    if (isset($known[$key])) {
                        continue;
                    }

                    $this->createQuestion($category, $index, Difficulty::from($difficulty), $ar, $en, $answer, $key, $row[4] ?? []);
                    $known[$key] = true;
                    $added++;
                }
            }

            return $added;
        });

        $this->say("Content: {$added} new question(s) added.");
    }

    /**
     * Find or create every category, top-level ones first, then each subcategory under its parent.
     *
     * @param  array<string, array{ar: string, en: string, icon: string, parent?: string}>  $definitions
     * @return array<string, Category>
     */
    private function categories(array $definitions): array
    {
        $categories = [];
        $top = 0;
        $below = [];

        foreach ($definitions as $slug => $definition) {
            if (! isset($definition['parent'])) {
                $categories[$slug] = $this->category($slug, $definition, null, ++$top);
            }
        }
        foreach ($definitions as $slug => $definition) {
            if (isset($definition['parent'])) {
                $parent = $categories[$definition['parent']] ?? Category::query()->where('slug', $definition['parent'])->firstOrFail();
                $below[$parent->slug] = ($below[$parent->slug] ?? 0) + 1;
                $categories[$slug] = $this->category($slug, $definition, $parent, $below[$parent->slug]);
            }
        }

        return $categories;
    }

    /** @param array{ar: string, en: string, icon: string} $definition */
    private function category(string $slug, array $definition, ?Category $parent, int $order): Category
    {
        return Category::firstOrCreate(
            ['slug' => $slug],
            [
                'parent_id' => $parent?->id,
                'name' => ['ar' => $definition['ar'], 'en' => $definition['en']],
                'icon' => $definition['icon'],
                'is_active' => true,
                'sort_order' => $order,
            ],
        );
    }

    /**
     * Every question that is already there, by key: its stored key, and for the ones without (written by an admin) a key made from their
     * English text. Soft-deleted questions included.
     *
     * @return array<string, true>
     */
    private function knownKeys(): array
    {
        $known = [];

        foreach (Question::withTrashed()->get(['id', 'text', 'seed_key']) as $question) {
            if ($question->seed_key !== null) {
                $known[$question->seed_key] = true;
            }
            if (($english = $question->getTranslation('text', 'en', false)) !== null && $english !== '') {
                $known[SeedKey::for($english)] ??= true;
            }
        }

        return $known;
    }

    /**
     * @param  list<string>|bool|int|float  $answer  four options, the correct one first ("arabic|english", or one string for both); true or false; or the number
     * @param  array{type?: string, tolerance?: int|float, image?: string, fact?: string|array{ar: string, en: string}}  $extra
     */
    private function createQuestion(Category $category, int $index, Difficulty $difficulty, string $ar, string $en, array|bool|int|float $answer, string $key, array $extra = []): void
    {
        $type = QuestionType::from($extra['type'] ?? QuestionType::MultipleChoice->value);

        $question = $category->questions()->make([
            'text' => ['ar' => $ar, 'en' => $en],
            'difficulty' => $difficulty,
            'type' => $type,
            'answer_number' => $type === QuestionType::ClosestNumber ? (float) $answer : null,
            'tolerance' => $type === QuestionType::ClosestNumber ? ($extra['tolerance'] ?? null) : null,
            'explanation' => isset($extra['fact']) ? $this->pair($extra['fact']) : null,
            'image_path' => isset($extra['image']) ? app(QuestionImage::class)->storeShipped($extra['image']) : null,
            'is_active' => true,
        ]);
        $question->forceFill(['seed_key' => $key])->save();

        if ($type === QuestionType::TrueFalse) {
            $question->syncTrueFalse((bool) $answer);
        } elseif ($type === QuestionType::MultipleChoice && is_array($answer)) {
            $this->createOptions($question, $index, $answer);
        }
    }

    /**
     * "arabic|english" (or an {ar, en} pair already) as an {ar, en} pair.
     *
     * @param  string|array{ar: string, en: string}  $text
     * @return array{ar: string, en: string}
     */
    private function pair(string|array $text): array
    {
        [$ar, $en] = is_array($text) ? [$text['ar'], $text['en']] : explode('|', $text, 2);

        return ['ar' => $ar, 'en' => $en];
    }

    /** @param list<string> $options the correct option first, then the wrong ones */
    private function createOptions(Question $question, int $index, array $options): void
    {
        // Spread the right answer over the four positions (by the question's place in its category) so it is not always first.
        $correctAt = ($index * 3 + 1) % 4;
        $options = array_map(fn (string $option) => str_contains($option, '|') ? explode('|', $option, 2) : [$option, $option], $options);
        $correct = array_shift($options);
        array_splice($options, $correctAt, 0, [$correct]);

        foreach ($options as $position => [$optionAr, $optionEn]) {
            $question->options()->create([
                'text' => ['ar' => $optionAr, 'en' => $optionEn],
                'is_correct' => $position === $correctAt,
                'sort_order' => $position + 1,
            ]);
        }
    }
}
