<?php

namespace Database\Factories;

use App\Enums\BackupRunStatus;
use App\Enums\BackupScope;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRun>
 */
class BackupRunFactory extends Factory
{
    /**
     * A completed run; workspace and website come from its policy.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'backup_policy_id' => BackupPolicy::factory(),
            'workspace_id' => fn (array $attributes): int => BackupPolicy::query()->whereKey($attributes['backup_policy_id'])->value('workspace_id'),
            'website_id' => fn (array $attributes): int => BackupPolicy::query()->whereKey($attributes['backup_policy_id'])->value('website_id'),
            'trigger_type' => 'scheduled',
            'backup_type' => 'full',
            'scope' => BackupScope::FullStack,
            'status' => BackupRunStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'duration_seconds' => 300,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => BackupRunStatus::Failed, 'completed_at' => now()]);
    }
}
