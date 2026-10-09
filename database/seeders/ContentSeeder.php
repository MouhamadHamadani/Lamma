<?php

namespace Database\Seeders;

use App\Enums\Difficulty;
use App\Models\Category;
use App\Models\Question;
use Database\Seeders\Concerns\ReportsToConsole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The categories and questions Lamma ships with (database/seeders/data/questions.php). Safe to run in production, and on every deploy:
 * it only ADDS what is missing. A category is found by its slug and a question by its English text (soft-deleted ones included, so a
 * question an admin removed does not come back). Nothing that exists is changed, so admin edits survive.
 */
class ContentSeeder extends Seeder
{
    use ReportsToConsole;

    public function run(): void
    {
        /** @var array{categories: array<string, array{ar: string, en: string, icon: string}>, questions: array<string, list<array{0: string, 1: string, 2: string, 3: list<string>}>>} $data */
        $data = require database_path('seeders/data/questions.php');

        $added = DB::transaction(function () use ($data) {
            $added = 0;

            foreach (array_keys($data['categories']) as $order => $slug) {
                $category = Category::firstOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => ['ar' => $data['categories'][$slug]['ar'], 'en' => $data['categories'][$slug]['en']],
                        'icon' => $data['categories'][$slug]['icon'],
                        'is_active' => true,
                        'sort_order' => $order + 1,
                    ],
                );

                $known = Question::withTrashed()->where('category_id', $category->id)->get()
                    ->map(fn (Question $question) => $question->getTranslation('text', 'en'))->flip();

                foreach ($data['questions'][$slug] ?? [] as $index => [$difficulty, $ar, $en, $options]) {
                    if ($known->has($en)) {
                        continue;
                    }

                    $this->createQuestion($category, $index, Difficulty::from($difficulty), $ar, $en, $options);
                    $added++;
                }
            }

            return $added;
        });

        $this->say("Content: {$added} new question(s) added.");
    }

    /**
     * @param  list<string>  $options  the correct option first, then three wrong ones ("arabic|english", or one string for both)
     */
    private function createQuestion(Category $category, int $index, Difficulty $difficulty, string $ar, string $en, array $options): void
    {
        $question = $category->questions()->create([
            'text' => ['ar' => $ar, 'en' => $en],
            'difficulty' => $difficulty,
            'is_active' => true,
        ]);

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
