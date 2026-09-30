<?php

namespace Database\Factories;

use App\Enums\Difficulty;
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

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
