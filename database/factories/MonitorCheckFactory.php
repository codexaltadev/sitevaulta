<?php

namespace Database\Factories;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitorCheck>
 */
class MonitorCheckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'status' => MonitorStatus::Up,
            'http_status_code' => 200,
            'response_time_ms' => fake()->numberBetween(80, 900),
            'region' => 'primary',
            'checked_at' => now()->utc(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => MonitorStatus::Down,
            'http_status_code' => 503,
            'error_message' => 'Website returned HTTP 503',
        ]);
    }
}
