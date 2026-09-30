<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionOption>
 */
class QuestionOptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question_id' => Question::factory(),
            'text' => [
                'ar' => 'خيار '.fake()->numberBetween(1, 99999),
                'en' => ucfirst(fake()->words(2, true)),
            ],
            'is_correct' => false,
            'sort_order' => 0,
        ];
    }

    public function correct(): static
    {
        return $this->state(['is_correct' => true]);
    }
}
