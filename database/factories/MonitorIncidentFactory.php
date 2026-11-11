<?php

namespace Database\Factories;

use App\Enums\MonitorIncidentStatus;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitorIncident>
 */
class MonitorIncidentFactory extends Factory
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
            'workspace_id' => fn (array $attributes): int => Monitor::find($attributes['monitor_id'])->workspace_id,
            'status' => MonitorIncidentStatus::Ongoing,
            'root_cause_type' => 'http',
            'root_cause' => 'Website returned HTTP 503',
            'region' => 'primary',
            'started_at' => now()->utc()->subMinutes(30),
            'alerted_at' => fn (array $attributes) => $attributes['started_at'],
        ];
    }

    /**
     * An incident still inside its monitor's alert delay: nobody has been told yet.
     */
    public function notYetAlerted(): static
    {
        return $this->state(fn (): array => ['alerted_at' => null]);
    }

    public function resolved(int $durationSeconds = 600): static
    {
        return $this->state(fn (): array => [
            'status' => MonitorIncidentStatus::Resolved,
            'resolved_at' => fn (array $attributes) => $attributes['started_at']->copy()->addSeconds($durationSeconds),
            'duration_seconds' => $durationSeconds,
        ]);
    }
}
