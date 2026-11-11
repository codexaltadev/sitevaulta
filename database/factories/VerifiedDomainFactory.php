<?php

namespace Database\Factories;

use App\Models\VerifiedDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VerifiedDomain>
 */
class VerifiedDomainFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain' => fake()->unique()->domainName(),
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'verification_method' => 'dns_txt',
            'verified_at' => now(),
        ]);
    }
}
