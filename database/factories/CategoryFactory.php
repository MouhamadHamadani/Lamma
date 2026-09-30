<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $en = fake()->unique()->words(2, true);

        return [
            'name' => ['ar' => 'فئة '.fake()->unique()->numberBetween(1, 99999), 'en' => ucfirst($en)],
            'slug' => Str::slug($en),
            'icon' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
