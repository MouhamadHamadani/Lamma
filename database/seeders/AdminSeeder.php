<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');

        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL / ADMIN_PASSWORD not set in .env; skipping admin.');

            return;
        }

        Admin::updateOrCreate(
            ['email' => $email],
            ['name' => config('admin.name'), 'password' => $password],
        );
    }
}
