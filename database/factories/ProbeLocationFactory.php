<?php

namespace Database\Factories;

use App\Models\ProbeLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProbeLocation>
 */
class ProbeLocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $region = fake()->unique()->randomElement(['singapore', 'frankfurt', 'virginia', 'mumbai', 'tokyo']);

        return [
            'name' => ucfirst($region),
            'region' => $region,
            'url' => "https://{$region}.probe.test",
            'secret' => ProbeLocation::generateSecret(),
            'public_ips' => [fake()->unique()->ipv4()],
            'is_enabled' => true,
            'health_status' => ProbeLocation::HEALTHY,
        ];
    }

    public function unreachable(): static
    {
        return $this->state(fn (): array => ['health_status' => ProbeLocation::UNREACHABLE]);
    }
}
