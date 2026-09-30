<?php

namespace Database\Factories;

use App\Models\MessageTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageTemplate>
 */
class MessageTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'category_id' => null,
            'message' => fake()->paragraph(),
            'attachment_id' => null,
            'tags' => [],
            'usage_count' => 0,
            'last_used_at' => null,
        ];
    }
}
