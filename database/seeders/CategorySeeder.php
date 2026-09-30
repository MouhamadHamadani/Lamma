<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'general-knowledge', 'icon' => '💡', 'name' => ['ar' => 'معلومات عامة', 'en' => 'General Knowledge']],
            ['slug' => 'science', 'icon' => '🔬', 'name' => ['ar' => 'علوم', 'en' => 'Science']],
            ['slug' => 'geography', 'icon' => '🌍', 'name' => ['ar' => 'جغرافيا', 'en' => 'Geography']],
        ];

        foreach ($categories as $order => $category) {
            Category::updateOrCreate(
                ['slug' => $category['slug']],
                ['name' => $category['name'], 'icon' => $category['icon'], 'is_active' => true, 'sort_order' => $order + 1],
            );
        }
    }
}
