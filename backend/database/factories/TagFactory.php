<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tag>
 */
class TagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $colors = [
            '#6366f1', // indigo
            '#8b5cf6', // violet
            '#ec4899', // pink
            '#f43f5e', // rose
            '#f97316', // orange
            '#eab308', // yellow
            '#22c55e', // green
            '#14b8a6', // teal
            '#06b6d4', // cyan
            '#3b82f6', // blue
        ];

        return [
            'name' => fake()->unique()->words(2, true),
            'color' => fake()->randomElement($colors),
        ];
    }
}
