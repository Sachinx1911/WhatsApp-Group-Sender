<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = config('educationhub.admin');

        if (blank($admin['password'])) {
            throw new RuntimeException('Set ADMIN_PASSWORD in .env before seeding the admin account.');
        }

        // The example file ships a placeholder; going live with it would leave the
        // login guessable by anyone who has seen the repository.
        if (in_array(strtolower($admin['password']), ['change-me', 'changeme', 'password', 'admin'], true) || strlen($admin['password']) < 8) {
            throw new RuntimeException('ADMIN_PASSWORD in .env must be a real password of at least 8 characters (not the example value).');
        }

        User::updateOrCreate(
            ['email' => $admin['email']],
            ['name' => $admin['name'], 'password' => $admin['password'], 'email_verified_at' => now()],
        );
    }
}
