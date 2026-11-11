<?php

namespace Database\Factories;

use App\Models\StatusPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusPage>
 */
class StatusPageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Acme Status',
            'slug' => fake()->unique()->slug(2),
            'description' => 'Live status of our services.',
            'is_published' => true,
            'show_uptime_bars' => true,
            'show_incident_history' => true,
        ];
    }

    public function passwordProtected(string $password = 'secret-pass'): static
    {
        return $this->state(fn (): array => ['password' => $password]);
    }
}
