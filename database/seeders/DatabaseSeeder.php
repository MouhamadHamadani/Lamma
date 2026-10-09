<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Locally: the admin from .env, the content and the test players. In production: the content only. The admin is created on purpose,
     * with `php artisan db:seed --class=AdminSeeder --force` (see docs/deployment.md), and the test players never.
     */
    public function run(): void
    {
        if (! app()->isProduction()) {
            $this->call(AdminSeeder::class);
        }

        $this->call(ContentSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DevSeeder::class);
        }
    }
}
