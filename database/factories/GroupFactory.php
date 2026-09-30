<?php

namespace Database\Factories;

use App\Enums\GroupStatus;
use App\Models\Category;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Batch '.fake()->unique()->numerify('####'),
            'category_id' => Category::factory(),
            'member_count' => fake()->numberBetween(80, 256),
            'whatsapp_identifier' => null,
            'status' => GroupStatus::Active,
            'last_sent_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => GroupStatus::Inactive]);
    }
}
