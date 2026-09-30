<?php

namespace Database\Factories;

use App\Enums\SendStatus;
use App\Models\Campaign;
use App\Models\SendLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SendLog>
 */
class SendLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'group_id' => null,
            'group_name' => 'Batch '.fake()->numerify('##'),
            'status' => SendStatus::Sent,
            'message' => 'Message sent successfully',
        ];
    }
}
