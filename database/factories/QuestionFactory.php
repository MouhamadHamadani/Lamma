<?php

namespace Database\Factories;

use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Models\Category;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'text' => [
                'ar' => 'سؤال رقم '.fake()->unique()->numberBetween(1, 99999).'؟',
                'en' => rtrim(fake()->unique()->sentence(6), '.').'?',
            ],
            'difficulty' => fake()->randomElement(Difficulty::cases()),
            'type' => QuestionType::MultipleChoice,
            'image_path' => null,
            'is_active' => true,
        ];
    }

    /** Create $count translated options, the first one correct. */
    public function withOptions(int $count = 4): static
    {
        return $this->afterCreating(function (Question $question) use ($count) {
            for ($i = 1; $i <= $count; $i++) {
                QuestionOption::factory()->for($question)->create([
                    'is_correct' => $i === 1,
                    'sort_order' => $i,
                ]);
            }
        });
    }

    /** A true/false question: True and False, in that order; $answer is the correct one. */
    public function trueFalse(bool $answer = true): static
    {
        return $this->state(['type' => QuestionType::TrueFalse])
            ->afterCreating(fn (Question $question) => $question->syncTrueFalse($answer));
    }

    /** A closest-number question. A null tolerance is the default one (a quarter of the answer, at least 1). */
    public function closestNumber(float $answer = 1969, ?float $tolerance = null): static
    {
        return $this->state(['type' => QuestionType::ClosestNumber, 'answer_number' => $answer, 'tolerance' => $tolerance]);
    }

    /** A picture question: this image path (a file on the question_images disk, or just a name for tests that never load it). */
    public function withImage(string $path = 'picture.webp'): static
    {
        return $this->state(['image_path' => $path]);
    }

    /** A fun fact shown with the answer. */
    public function withFact(string $en = 'A fun fact.', string $ar = 'معلومة طريفة.'): static
    {
        return $this->state(['explanation' => ['ar' => $ar, 'en' => $en]]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
