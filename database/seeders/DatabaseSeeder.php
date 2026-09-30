<?php

namespace Database\Seeders;

use App\Models\WhatsAppSession;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Essential data first (needed for real use), then realistic demo data so every
     * screen looks complete right after installation.
     */
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            CategorySeeder::class,
        ]);

        WhatsAppSession::current();

        $this->call(DemoDataSeeder::class);
    }
}
