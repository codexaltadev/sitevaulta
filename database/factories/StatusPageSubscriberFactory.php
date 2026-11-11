<?php

namespace Database\Factories;

use App\Models\StatusPageSubscriber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusPageSubscriber>
 */
class StatusPageSubscriberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => ['confirmed_at' => now()]);
    }
}
