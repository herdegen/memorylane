<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Household>
 */
class HouseholdFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Foyer '.fake()->lastName(),
            'kind' => 'family',
            'invite_policy' => 'everyone',
            'created_by' => User::factory(),
        ];
    }

    public function friends(): static
    {
        return $this->state(fn () => ['name' => 'Amis '.fake()->city(), 'kind' => 'friends', 'invite_policy' => 'admin']);
    }
}
