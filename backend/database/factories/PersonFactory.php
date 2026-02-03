<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Person>
 */
class PersonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'birth_date' => fake()->optional(0.5)->dateTimeBetween('-80 years', '-5 years'),
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }
}
