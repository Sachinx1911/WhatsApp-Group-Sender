<?php

namespace Database\Seeders;

use App\Enums\GroupStatus;
use App\Models\Category;
use App\Models\Group;
use Illuminate\Database\Seeder;

class DemoGroupSeeder extends Seeder
{
    public const TEST_GROUP = 'Education Hub Test Group';

    /** category => [name prefix, count, member range] */
    private const BATCHES = [
        'MPSC' => ['MPSC Batch', 10, [180, 256]],
        'Police Bharti' => ['Police Batch', 6, [150, 256]],
        'Combined' => ['Combined Batch', 5, [120, 240]],
        'Free' => ['Free Batch', 5, [400, 900]],
        'Premium' => ['Premium Batch', 4, [45, 110]],
    ];

    private const INACTIVE = ['MPSC Batch 10', 'Free Batch 05'];

    public function run(): void
    {
        $categories = Category::pluck('id', 'name');

        foreach (self::BATCHES as $category => [$prefix, $count, [$min, $max]]) {
            for ($i = 1; $i <= $count; $i++) {
                $name = sprintf('%s %02d', $prefix, $i);

                $this->upsert($name, $categories[$category], mt_rand($min, $max));
            }
        }

        $this->upsert(self::TEST_GROUP, $categories['Other'], 2);
    }

    private function upsert(string $name, int $categoryId, int $members): void
    {
        $createdAt = now()->subDays(mt_rand(20, 90))->setTime(mt_rand(9, 20), mt_rand(0, 59));

        $group = Group::updateOrCreate(['name' => $name], [
            'category_id' => $categoryId,
            'member_count' => $members,
            'status' => in_array($name, self::INACTIVE, true) ? GroupStatus::Inactive : GroupStatus::Active,
        ]);

        $group->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
    }
}
