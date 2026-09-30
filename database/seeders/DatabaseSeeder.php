<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            CategorySeeder::class,
            QuestionSeeder::class,
        ]);

        // Local test players (password for all: "password").
        User::factory()->create(['name' => 'Test User', 'email' => 'test@example.com', 'preferred_locale' => 'ar']);
        User::factory()->create(['name' => 'English Player', 'email' => 'english@example.com', 'preferred_locale' => 'en']);
        User::factory(3)->create();
    }
}
