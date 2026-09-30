<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'color' => fake()->randomElement(['#2563EB', '#7C3AED', '#10B981', '#F59E0B', '#0EA5E9', '#64748B']),
            'sort_order' => fake()->numberBetween(0, 50),
        ];
    }
}
