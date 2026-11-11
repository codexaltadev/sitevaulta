<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Verification\DomainVerificationService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Website>
 */
class WebsiteFactory extends Factory
{
    /**
     * Uptime monitoring is off by default so a test only gets the monitors it creates itself.
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
            'name' => ucfirst(strtok($domain, '.')),
            'url' => "https://{$domain}",
            'environment' => 'production',
            'status' => 'active',
            'uptime_monitoring_enabled' => false,
        ];
    }

    /**
     * Domain ownership proven (on the website's verified_domains row).
     */
    public function verified(string $method = 'dns_txt'): static
    {
        return $this->afterCreating(function (Website $website) use ($method): void {
            DomainVerificationService::ensureChallengeExists($website)->markProven($method);
            $website->unsetRelation('verifiedDomain');
        });
    }
}
