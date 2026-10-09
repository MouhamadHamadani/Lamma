<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\ReportsToConsole;
use Illuminate\Database\Seeder;

/**
 * Test players for a local machine (password for all: "password"). It does nothing in production, even when called by name, so these
 * well-known accounts can never exist on the live site.
 */
class DevSeeder extends Seeder
{
    use ReportsToConsole;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->say('DevSeeder skipped: it never runs in production.', warning: true);

            return;
        }

        $created = false;
        foreach ([
            ['name' => 'Test User', 'email' => 'test@example.com', 'preferred_locale' => 'ar'],
            ['name' => 'English Player', 'email' => 'english@example.com', 'preferred_locale' => 'en'],
        ] as $user) {
            if (! User::where('email', $user['email'])->exists()) {
                User::factory()->create($user);
                $created = true;
            }
        }

        if ($created) {
            User::factory(3)->create();
        }
    }
}
