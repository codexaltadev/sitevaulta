<?php

namespace Database\Factories;

use App\Enums\StorageDriver;
use App\Models\StorageConnection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StorageConnection>
 */
class StorageConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => fn (): int => Workspace::create([
                'name' => fake()->company(),
                'slug' => fake()->unique()->slug(2),
                'owner_id' => User::factory()->create()->id,
            ])->id,
            'name' => 'Backups bucket',
            'driver' => StorageDriver::S3,
            'credentials' => ['bucket' => 'backups', 'region' => 'ap-southeast-1', 'key' => 'test', 'secret' => 'test'],
        ];
    }
}
