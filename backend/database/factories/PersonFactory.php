<?php

namespace Database\Factories;

use App\Models\Person;
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
            'gender' => fake()->randomElement(['M', 'F', 'U']),
            'birth_date' => fake()->optional(0.5)->dateTimeBetween('-80 years', '-5 years'),
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }

    public function male(): static
    {
        return $this->state(fn () => ['gender' => 'M']);
    }

    public function female(): static
    {
        return $this->state(fn () => ['gender' => 'F']);
    }

    public function withFather(Person $father): static
    {
        return $this->state(fn () => ['father_id' => $father->id]);
    }

    public function withMother(Person $mother): static
    {
        return $this->state(fn () => ['mother_id' => $mother->id]);
    }
}
