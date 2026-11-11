<?php

namespace Database\Factories;

use App\Enums\MaintenanceScheduleType;
use App\Models\MaintenanceWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceWindow>
 */
class MaintenanceWindowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Server upgrade',
            'schedule_type' => MaintenanceScheduleType::OneTime,
            'starts_at' => now()->utc()->subMinutes(10),
            'duration_minutes' => 60,
            'is_active' => true,
        ];
    }

    /**
     * @param  list<int>  $daysOfWeek  0 = Sunday
     */
    public function weekly(array $daysOfWeek, string $startTime, int $durationMinutes = 60): static
    {
        return $this->state(fn (): array => [
            'schedule_type' => MaintenanceScheduleType::Weekly,
            'starts_at' => null,
            'start_time' => $startTime,
            'days_of_week' => $daysOfWeek,
            'duration_minutes' => $durationMinutes,
        ]);
    }

    public function monthly(int $dayOfMonth, string $startTime, int $durationMinutes = 60): static
    {
        return $this->state(fn (): array => [
            'schedule_type' => MaintenanceScheduleType::Monthly,
            'starts_at' => null,
            'start_time' => $startTime,
            'day_of_month' => $dayOfMonth,
            'duration_minutes' => $durationMinutes,
        ]);
    }
}
