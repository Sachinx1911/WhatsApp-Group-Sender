<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/** Default categories (docs/MASTER_PROMPT.md §13). The admin can edit them later. */
class CategorySeeder extends Seeder
{
    public const DEFAULTS = [
        'MPSC' => '#2563EB',
        'Police Bharti' => '#7C3AED',
        'Combined' => '#0EA5E9',
        'Free' => '#10B981',
        'Premium' => '#F59E0B',
        'Other' => '#64748B',
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::DEFAULTS as $name => $color) {
            Category::firstOrCreate(['name' => $name], ['color' => $color, 'sort_order' => $order++]);
        }
    }
}
