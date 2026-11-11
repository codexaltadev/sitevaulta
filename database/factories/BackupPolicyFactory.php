<?php

namespace Database\Factories;

use App\Models\BackupPolicy;
use App\Models\ServerConnection;
use App\Models\StorageConnection;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A policy whose website, server connection and storage all belong to the same workspace.
 *
 * @extends Factory<BackupPolicy>
 */
class BackupPolicyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'website_id' => Website::factory()->verified(),
            'workspace_id' => fn (array $attributes): int => Website::query()->whereKey($attributes['website_id'])->value('workspace_id'),
            'server_connection_id' => fn (array $attributes): int => ServerConnection::factory()->create([
                'website_id' => $attributes['website_id'],
                'workspace_id' => $attributes['workspace_id'],
            ])->id,
            'storage_connection_id' => fn (array $attributes): int => StorageConnection::factory()->create([
                'workspace_id' => $attributes['workspace_id'],
            ])->id,
            'name' => 'Nightly full backup',
            'status' => 'active',
        ];
    }
}
