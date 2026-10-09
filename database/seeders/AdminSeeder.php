<?php

namespace Database\Seeders;

use App\Models\Admin;
use Database\Seeders\Concerns\ReportsToConsole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/**
 * The Filament admin from ADMIN_EMAIL / ADMIN_PASSWORD. Locally a missing value just skips it. In production it refuses (and the command
 * fails) unless there is a valid email and a strong password, so the live site never gets a weak or default admin. Running it again
 * resets that admin's password to the one in .env.
 */
class AdminSeeder extends Seeder
{
    use ReportsToConsole;

    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');

        if (app()->isProduction()) {
            $this->assertProductionCredentials($email, $password);
        } elseif (! $email || ! $password) {
            $this->say('ADMIN_EMAIL / ADMIN_PASSWORD not set in .env; skipping admin.', warning: true);

            return;
        }

        Admin::updateOrCreate(
            ['email' => $email],
            ['name' => config('admin.name'), 'password' => $password, 'is_active' => true],
        );
    }

    private function assertProductionCredentials(mixed $email, mixed $password): void
    {
        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('AdminSeeder refused: set ADMIN_EMAIL and ADMIN_PASSWORD in .env first (production never uses defaults).');
        }

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['email'], 'password' => [Password::min(12)->mixedCase()->letters()->numbers()->symbols()]],
        );

        if ($validator->fails()) {
            throw new RuntimeException('AdminSeeder refused: '.implode(' ', $validator->errors()->all()));
        }
    }
}
