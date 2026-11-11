<?php

namespace Tests\Feature;

use App\Enums\MonitorIncidentStatus;
use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\VerifiedDomain;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\NetworkProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mockery\MockInterface;
use Tests\TestCase;

class MonitorCheckRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_check_marks_monitor_up_and_records_history(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake(['https://shop.test/' => Http::response('<h1>Shop</h1>', 200)]);
        $monitor = Monitor::factory()->create(['url' => 'https://shop.test/']);

        app(MonitorCheckRunner::class)->run($monitor);

        $monitor->refresh();
        $this->assertSame(MonitorStatus::Up, $monitor->status);
        $this->assertSame(200, $monitor->last_http_status_code);
        $this->assertSame(now()->toDateTimeString(), $monitor->last_status_changed_at->toDateTimeString());
        $this->assertDatabaseHas('monitor_checks', [
            'monitor_id' => $monitor->id,
            'status' => 'up',
            'http_status_code' => 200,
        ]);
        $this->assertDatabaseCount('monitor_incidents', 0);
    }

    public function test_confirmed_failure_opens_incident_with_activity_log(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://shop.test/' => Http::response('Service Unavailable', 503)]);
        $monitor = Monitor::factory()->up()->create(['url' => 'https://shop.test/']);

        app(MonitorCheckRunner::class)->run($monitor);

        Http::assertSentCount(2);
        $this->assertSame(MonitorStatus::Down, $monitor->refresh()->status);

        $incident = MonitorIncident::sole();
        $this->assertSame(MonitorIncidentStatus::Ongoing, $incident->status);
        $this->assertSame('http', $incident->root_cause_type);
        $this->assertSame('HTTP 503 Service Unavailable', $incident->root_cause);
        $this->assertSame(['method' => 'GET', 'url' => 'https://shop.test/', 'headers' => ['User-Agent' => 'SiteVaulta-Monitor/1.0 (+'.route('monitoring.ips').')']], $incident->request_snapshot);
        $this->assertSame('Service Unavailable', $incident->response_body);
        $this->assertSame(
            ['detected', 'confirmed', 'alert_sent'],
            $incident->events()->orderBy('id')->pluck('type')->all(),
        );
    }

    public function test_failure_that_recovers_on_recheck_does_not_open_incident(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://shop.test/' => Http::sequence()->push('down', 503)->push('ok', 200)]);
        $monitor = Monitor::factory()->up()->create(['url' => 'https://shop.test/']);

        app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame(MonitorStatus::Up, $monitor->refresh()->status);
        $this->assertDatabaseCount('monitor_incidents', 0);
    }

    public function test_keyword_monitor_goes_down_when_required_keyword_is_missing(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://shop.test/' => Http::response('<p>Database error</p>', 200)]);
        $monitor = Monitor::factory()->keyword('Add to cart')->create(['url' => 'https://shop.test/']);

        app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame(MonitorStatus::Down, $monitor->refresh()->status);
        $incident = MonitorIncident::sole();
        $this->assertSame('keyword', $incident->root_cause_type);
        $this->assertSame('Keyword "Add to cart" not found', $incident->root_cause);
    }

    public function test_keyword_monitor_goes_down_when_forbidden_keyword_is_present(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://shop.test/' => Http::response('<p>FATAL ERROR in index.php</p>', 200)]);
        $monitor = Monitor::factory()->keyword('fatal error', shouldExist: false)->create(['url' => 'https://shop.test/']);

        app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame(MonitorStatus::Down, $monitor->refresh()->status);
        $this->assertSame('Keyword "fatal error" found', MonitorIncident::sole()->root_cause);
    }

    public function test_dns_failure_is_reported_as_dns_root_cause(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://missing.test/' => Http::failedConnection('cURL error 6: Could not resolve host: missing.test')]);
        $monitor = Monitor::factory()->create(['url' => 'https://missing.test/']);

        app(MonitorCheckRunner::class)->run($monitor);

        $incident = MonitorIncident::sole();
        $this->assertSame('dns', $incident->root_cause_type);
        $this->assertSame('DNS Resolving problem', $incident->root_cause);
    }

    /**
     * The probe's own network: control hosts resolve and accept TCP 443 only when $online is true.
     */
    private function fakeProbeNetwork(bool $online): void
    {
        config(['monitoring.control_hosts' => ['control.example']]);
        $this->mock(NetworkProbe::class, function (MockInterface $mock) use ($online): void {
            $mock->shouldReceive('resolveIps')->andReturnUsing(fn (string $host): array => $host === 'control.example' && $online ? ['93.184.216.34'] : []);
            $mock->shouldReceive('tcpConnect')->andReturn(['connected' => $online, 'latency_ms' => $online ? 20 : null]);
        });
    }

    public function test_dns_failure_while_the_probe_itself_is_offline_records_nothing_and_alerts_no_one(): void
    {
        $this->fakeProbeNetwork(online: false);
        Http::preventStrayRequests();
        Http::fake(['https://shop.test/' => Http::failedConnection('cURL error 6: Could not resolve host: shop.test')]);
        $monitor = Monitor::factory()->up()->create(['url' => 'https://shop.test/', 'last_checked_at' => now()->subMinutes(5)]);
        $lastCheckedAt = $monitor->last_checked_at->toDateTimeString();

        $check = app(MonitorCheckRunner::class)->run($monitor);

        $this->assertFalse($check->exists);
        $this->assertStringContainsString('skipped: the monitoring probe lost its own network', $check->error_message);
        $this->assertSame(MonitorStatus::Up, $monitor->refresh()->status);
        $this->assertSame($lastCheckedAt, $monitor->last_checked_at->toDateTimeString(), 'Re-checked on the next tick.');
        $this->assertDatabaseCount('monitor_checks', 0);
        $this->assertDatabaseCount('monitor_incidents', 0);
    }

    public function test_dns_failure_counts_when_the_probe_network_is_healthy(): void
    {
        $this->fakeProbeNetwork(online: true);
        Http::preventStrayRequests();
        Http::fake(['https://shop.test/' => Http::failedConnection('cURL error 6: Could not resolve host: shop.test')]);
        $monitor = Monitor::factory()->up()->create(['url' => 'https://shop.test/']);

        app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame('dns', MonitorIncident::sole()->root_cause_type);
    }

    public function test_an_http_error_proves_the_probe_reached_the_site_so_it_counts_even_when_the_self_check_fails(): void
    {
        $this->fakeProbeNetwork(online: false);
        Http::preventStrayRequests();
        Http::fake(['https://shop.test/' => Http::response('Bad gateway', 502)]);
        $monitor = Monitor::factory()->up()->create(['url' => 'https://shop.test/']);

        app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame('http', MonitorIncident::sole()->root_cause_type);
    }

    public function test_confirmation_recheck_waits_the_configured_pause(): void
    {
        Sleep::fake();
        config(['monitoring.confirmation_delay_seconds' => 3]);
        Http::preventStrayRequests();
        Http::fake(['https://shop.test/' => Http::sequence()->push('Down', 503)->push('OK', 200)]);
        $monitor = Monitor::factory()->up()->create(['url' => 'https://shop.test/']);

        app(MonitorCheckRunner::class)->run($monitor);

        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(3)->seconds()]);
        $this->assertDatabaseCount('monitor_incidents', 0);
    }

    public function test_repeated_failure_keeps_a_single_ongoing_incident(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://shop.test/' => Http::response('down', 500)]);
        $monitor = Monitor::factory()->create(['url' => 'https://shop.test/']);
        $runner = app(MonitorCheckRunner::class);

        $runner->run($monitor);
        $runner->run($monitor->refresh());

        $this->assertDatabaseCount('monitor_incidents', 1);
        $this->assertSame(2, $monitor->refresh()->consecutive_failures);
    }

    public function test_recovery_resolves_ongoing_incident_with_duration(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake(['https://shop.test/' => Http::response('ok', 200)]);
        $monitor = Monitor::factory()->down()->create(['url' => 'https://shop.test/']);
        $incident = MonitorIncident::factory()->for($monitor)->create(['started_at' => now()->subMinutes(14)]);

        app(MonitorCheckRunner::class)->run($monitor);

        $incident->refresh();
        $this->assertSame(MonitorIncidentStatus::Resolved, $incident->status);
        $this->assertSame(14 * 60, $incident->duration_seconds);
        $this->assertSame(['resolved', 'alert_sent'], $incident->events()->orderBy('id')->pluck('type')->all());
        $this->assertSame(MonitorStatus::Up, $monitor->refresh()->status);
    }

    public function test_port_monitor_reports_closed_port(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->with('db.example.com')->andReturn(['93.184.216.34']);
            $mock->shouldReceive('tcpConnect')->with('93.184.216.34', 3306, 2)->andReturn(['connected' => false, 'latency_ms' => null]);
        });
        $monitor = Monitor::factory()->create([
            'type' => 'port',
            'url' => null,
            'host' => 'db.example.com',
            'port' => 3306,
            'timeout_seconds' => 2,
        ]);
        VerifiedDomain::factory()->verified()->create(['workspace_id' => $monitor->workspace_id, 'domain' => 'db.example.com']);

        app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame(MonitorStatus::Down, $monitor->refresh()->status);
        $this->assertSame('port', MonitorIncident::sole()->root_cause_type);
    }

    public function test_command_checks_only_due_unpaused_monitors(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake(['https://due.test/' => Http::response('ok', 200)]);
        $due = Monitor::factory()->create(['url' => 'https://due.test/', 'last_checked_at' => now()->subMinutes(5)]);
        $notDue = Monitor::factory()->up()->create(['url' => 'https://fresh.test/', 'last_checked_at' => now()->subMinutes(4)]);
        $paused = Monitor::factory()->paused()->create(['url' => 'https://paused.test/']);

        $this->artisan('sitevaulta:check-monitors')
            ->expectsOutput('Queued 1 due monitors')
            ->assertSuccessful();

        $this->assertSame(1, $due->checks()->count());
        $this->assertSame(0, $notDue->checks()->count());
        $this->assertSame(0, $paused->checks()->count());
    }
}
