<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\MonitoringIdentity;
use App\Services\Monitoring\NetworkProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitoringIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_page_lists_configured_ips_user_agent_and_allowlisting_steps(): void
    {
        config(['monitoring.probe_ips' => ['93.184.216.34', '2606:4700:4700::1111', '10.0.0.5']]);

        $this->get('/monitoring-ips')
            ->assertOk()
            ->assertSee('93.184.216.34')
            ->assertSee('2606:4700:4700::1111')
            ->assertDontSee('10.0.0.5')
            ->assertSee('SiteVaulta-Monitor/1.0 (+'.route('monitoring.ips').')')
            ->assertSeeInOrder(['Cloudflare', 'Wordfence', 'CSF', 'Sucuri']);

        $this->get('/monitoring-ips.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText("93.184.216.34\n2606:4700:4700::1111\n", false);
    }

    public function test_public_ip_is_detected_and_only_a_success_is_cached(): void
    {
        config(['monitoring.probe_ips' => [], 'monitoring.detect_public_ip' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://api.ipify.org' => Http::sequence()->pushStatus(500)->push('93.184.216.34')]);
        $identity = app(MonitoringIdentity::class);

        $this->assertSame([], $identity->probeIps());
        $this->assertNull(Cache::get('monitoring.detected_public_ips'));

        $this->assertSame(['93.184.216.34'], $identity->probeIps());
        $this->assertSame(['93.184.216.34'], $identity->probeIps());
        Http::assertSentCount(2);
    }

    public function test_http_checks_identify_themselves_with_the_allowlist_url(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://shop.example/' => Http::response('ok')]);
        $this->mock(NetworkProbe::class, fn (MockInterface $mock) => $mock->shouldReceive('resolveIps')->andReturn(['93.184.216.34']));

        app(MonitorCheckRunner::class)->run(Monitor::factory()->create(['url' => 'https://shop.example/']));

        Http::assertSent(fn (Request $request): bool => $request->header('User-Agent') === ['SiteVaulta-Monitor/1.0 (+'.route('monitoring.ips').')']);
    }

    /**
     * @return array<string, array{int, array<string, string>, string, string, string}>
     */
    public static function blockedResponses(): array
    {
        return [
            'cloudflare challenge' => [403, ['Server' => 'cloudflare', 'CF-RAY' => '8a1b2c3d4e'], '<title>Just a moment...</title>', 'firewall', 'Blocked by Cloudflare (HTTP 403) — allowlist our monitoring IPs'],
            'wordfence block' => [503, [], '<h1>Your access to this site has been limited by the site owner</h1> Wordfence', 'firewall', 'Blocked by Wordfence (HTTP 503) — allowlist our monitoring IPs'],
            'sucuri' => [403, ['X-Sucuri-ID' => '15016'], 'Access Denied - Sucuri Website Firewall', 'firewall', 'Blocked by Sucuri (HTTP 403) — allowlist our monitoring IPs'],
            'rate limited' => [429, [], 'Too Many Requests', 'firewall', 'Rate limited (HTTP 429) — allowlist our monitoring IPs'],
            'real server error' => [500, ['Server' => 'nginx'], 'Internal Server Error', 'http', 'HTTP 500 Internal Server Error'],
            'cloudflare origin down' => [502, ['Server' => 'cloudflare', 'CF-RAY' => '8a1b2c3d4e'], 'Bad gateway', 'http', 'HTTP 502 Bad Gateway'],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('blockedResponses')]
    public function test_firewall_blocks_get_their_own_root_cause(int $status, array $headers, string $body, string $expectedType, string $expectedCause): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://shop.example/' => Http::response($body, $status, $headers)]);
        $this->mock(NetworkProbe::class, fn (MockInterface $mock) => $mock->shouldReceive('resolveIps')->andReturn(['93.184.216.34']));
        $monitor = Monitor::factory()->create(['url' => 'https://shop.example/']);

        app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame(MonitorStatus::Down, $monitor->refresh()->status);
        $incident = MonitorIncident::sole();
        $this->assertSame($expectedType, $incident->root_cause_type);
        $this->assertSame($expectedCause, $incident->root_cause);
    }
}
