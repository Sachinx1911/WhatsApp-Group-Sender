<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Realistic sample data (docs/MASTER_PROMPT.md §41). Deterministic: every run
 * produces the same groups, templates and history.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(2026);
        fake()->seed(2026);

        $this->call([
            DemoGroupSeeder::class,
            DemoMediaSeeder::class,
            DemoTemplateSeeder::class,
            DemoCampaignSeeder::class,
        ]);
    }
}
