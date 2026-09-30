<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Counters default to zero; they are kept in sync with campaign_groups by the
 * code that creates the rows.
 *
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'message' => fake()->paragraph(),
            'attachment_id' => null,
            'attachment_name' => null,
            'is_test' => false,
            'status' => CampaignStatus::Draft,
            'created_by' => null,
        ];
    }

    public function test(): static
    {
        return $this->state(['is_test' => true]);
    }
}
