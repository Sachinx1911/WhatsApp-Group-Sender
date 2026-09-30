<?php

namespace Database\Factories;

use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignGroup>
 */
class CampaignGroupFactory extends Factory
{
    protected $model = CampaignGroup::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'group_id' => Group::factory(),
            'group_name' => fn (array $attributes) => Group::find($attributes['group_id'])?->name ?? 'Unknown group',
            'status' => SendStatus::Pending,
            'attempts' => 0,
        ];
    }

    public function sent(): static
    {
        return $this->state(['status' => SendStatus::Sent, 'attempts' => 1, 'sent_at' => now()]);
    }

    public function failed(SendErrorType $type = SendErrorType::GroupNotFound): static
    {
        return $this->state([
            'status' => SendStatus::Failed,
            'attempts' => $type->isRetryable() ? 3 : 1,
            'error_type' => $type,
            'error_message' => $type->label(),
        ]);
    }
}
