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

        User::updateOrCreate(
            ['email' => $admin['email']],
            ['name' => $admin['name'], 'password' => $admin['password'], 'email_verified_at' => now()],
        );
    }
}
