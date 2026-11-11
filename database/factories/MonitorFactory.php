<?php

namespace Database\Factories;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Monitor>
 */
class MonitorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = fake()->unique()->domainName();

        return [
            'workspace_id' => fn (): int => Workspace::create([
                'name' => fake()->company(),
                'slug' => fake()->unique()->slug(2),
                'owner_id' => User::factory()->create()->id,
            ])->id,
            'name' => $domain,
            'type' => MonitorType::Http,
            'url' => "https://{$domain}/",
            'interval_seconds' => 300,
            'timeout_seconds' => 30,
            'status' => MonitorStatus::Pending,
        ];
    }

    public function up(): static
    {
        return $this->state(fn (): array => [
            'status' => MonitorStatus::Up,
            'last_checked_at' => now()->utc(),
            'last_status_changed_at' => now()->utc()->subHour(),
            'last_response_time_ms' => 180,
        ]);
    }

    public function down(): static
    {
        return $this->state(fn (): array => [
            'status' => MonitorStatus::Down,
            'last_checked_at' => now()->utc(),
            'last_status_changed_at' => now()->utc()->subMinutes(40),
            'last_error' => 'Website returned HTTP 503',
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'status' => MonitorStatus::Paused,
        ]);
    }

    public function keyword(string $keyword, bool $shouldExist = true): static
    {
        return $this->state(fn (): array => [
            'type' => MonitorType::Keyword,
            'keyword' => $keyword,
            'keyword_should_exist' => $shouldExist,
        ]);
    }
}
