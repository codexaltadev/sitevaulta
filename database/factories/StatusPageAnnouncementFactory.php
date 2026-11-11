<?php

namespace Database\Factories;

use App\Models\StatusPageAnnouncement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusPageAnnouncement>
 */
class StatusPageAnnouncementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'maintenance',
            'title' => 'Planned database upgrade',
            'body' => 'The checkout may be unavailable for up to 15 minutes.',
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => ['resolved_at' => now()]);
    }
}
