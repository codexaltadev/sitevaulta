<?php

namespace Database\Factories;

use App\Enums\FileProtocol;
use App\Models\ServerConnection;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerConnection>
 */
class ServerConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'workspace_id' => fn (array $attributes): int => Website::query()->whereKey($attributes['website_id'])->value('workspace_id'),
            'name' => 'Production server',
            'file_protocol' => FileProtocol::SshSftp,
            'file_credentials' => ['host' => fake()->domainName(), 'port' => 22, 'username' => 'deploy', 'simulation_mode' => true],
        ];
    }
}
