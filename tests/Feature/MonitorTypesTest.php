<?php

namespace Tests\Feature;

use App\Enums\MonitorIncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Filament\App\Resources\Monitors\Pages\ViewMonitor;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\MonitorSetupAdvisor;
use App\Services\Monitoring\NetworkProbe;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class MonitorTypesTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_monitor_records_latency_when_host_replies(): void
    {
        Process::fake(['*' => Process::result('64 bytes from 1.1.1.1: icmp_seq=1 ttl=57 time=12.4 ms')]);
        $monitor = Monitor::factory()->create(['type' => MonitorType::Ping, 'url' => null, 'host' => '1.1.1.1', 'timeout_seconds' => 5]);

        $check = app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame(MonitorStatus::Up, $check->status);
        $this->assertSame(12, $check->response_time_ms);
        $this->assertSame('icmp', $monitor->refresh()->last_probe_method);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['ping', '-c', '1', '-W', '5', '1.1.1.1']);
    }

    public function test_ping_reports_dns_failure_without_running_ping(): void
    {
        Process::fake();
        $probe = $this->partialMock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolves')->with('mdmilton.test')->andReturn(false);
        });

        $result = $probe->ping('mdmilton.test', 2);

        $this->assertSame(['reachable' => false, 'latency_ms' => null, 'error' => 'DNS Resolving problem'], $result);
        Process::assertNothingRan();
    }

    public function test_blocked_ping_falls_back_to_tcp_and_stays_up(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->with('daffodilvarsity.edu.bd')->andReturn(['13.235.168.200']);
            $mock->shouldReceive('ping')->with('13.235.168.200', 10)->andReturn(['reachable' => false, 'latency_ms' => null, 'error' => 'Host unreachable (no ping reply)']);
            $mock->shouldReceive('tcpConnect')->with('13.235.168.200', 443, 10)->andReturn(['connected' => true, 'latency_ms' => 28]);
        });
        $monitor = Monitor::factory()->create(['type' => MonitorType::Ping, 'url' => null, 'host' => 'daffodilvarsity.edu.bd']);

        $check = app(MonitorCheckRunner::class)->run($monitor);

        $monitor->refresh();
        $this->assertSame(MonitorStatus::Up, $check->status);
        $this->assertSame(28, $check->response_time_ms);
        $this->assertSame('tcp:443', $monitor->last_probe_method);
        $this->assertSame('28 ms (TCP)', $monitor->headlineMetric());
        $this->assertSame(0, MonitorIncident::count());
    }

    public function test_ping_goes_down_when_icmp_and_tcp_both_fail(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->andReturn(['93.184.216.34']);
            $mock->shouldReceive('ping')->andReturn(['reachable' => false, 'latency_ms' => null, 'error' => 'Host unreachable (no ping reply)']);
            $mock->shouldReceive('tcpConnect')->andReturn(['connected' => false, 'latency_ms' => null]);
        });
        $monitor = Monitor::factory()->create(['type' => MonitorType::Ping, 'url' => null, 'host' => '203.0.113.9']);

        app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame(MonitorStatus::Down, $monitor->refresh()->status);
        $this->assertSame('Host unreachable (no ping reply and TCP 443/80 closed)', MonitorIncident::sole()->root_cause);
    }

    public function test_strict_icmp_and_dns_failures_never_try_tcp(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->with('strict.test')->andReturn(['93.184.216.35']);
            $mock->shouldReceive('resolveIps')->with('gone.test')->andReturn([]);
            $mock->shouldReceive('ping')->with('93.184.216.35', 10)->andReturn(['reachable' => false, 'latency_ms' => null, 'error' => 'Host unreachable (no ping reply)']);
            $mock->shouldReceive('ping')->with('gone.test', 10)->andReturn(['reachable' => false, 'latency_ms' => null, 'error' => 'DNS Resolving problem']);
            $mock->shouldNotReceive('tcpConnect');
        });
        $strict = Monitor::factory()->create(['type' => MonitorType::Ping, 'url' => null, 'host' => 'strict.test', 'ping_strict_icmp' => true]);
        $gone = Monitor::factory()->create(['type' => MonitorType::Ping, 'url' => null, 'host' => 'gone.test']);

        app(MonitorCheckRunner::class)->run($strict);
        app(MonitorCheckRunner::class)->run($gone);

        $this->assertSame(MonitorStatus::Down, $strict->refresh()->status);
        $this->assertSame('dns', $gone->incidents()->sole()->root_cause_type);
    }

    public function test_ping_advisor_explains_blocked_ping_and_unreachable_hosts(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ping')->andReturn(['reachable' => false, 'latency_ms' => null, 'error' => 'Host unreachable (no ping reply)']);
            $mock->shouldReceive('tcpConnect')->with('blocked.test', 443, 3)->andReturn(['connected' => true, 'latency_ms' => 30]);
            $mock->shouldReceive('tcpConnect')->with('dead.test', \Mockery::any(), 3)->andReturn(['connected' => false, 'latency_ms' => null]);
        });
        $blocked = Monitor::factory()->create(['type' => MonitorType::Ping, 'url' => null, 'host' => 'blocked.test']);
        $dead = Monitor::factory()->create(['type' => MonitorType::Ping, 'url' => null, 'host' => 'dead.test']);
        $advisor = app(MonitorSetupAdvisor::class);

        $this->assertSame([[
            'level' => 'warning',
            'title' => 'blocked.test blocks ping (firewall)',
            'body' => "No problem — reachability and latency will be measured with a TCP connect on port 443 instead, so you won't get false alerts.",
        ]], $advisor->adviseFor($blocked));
        $this->assertSame('danger', $advisor->adviseFor($dead)[0]['level']);
        $this->assertSame('dead.test is not reachable', $advisor->adviseFor($dead)[0]['title']);
    }

    public function test_ping_never_runs_for_a_host_that_looks_like_an_option(): void
    {
        Process::fake();

        $result = app(NetworkProbe::class)->ping('-f', 2);

        $this->assertFalse($result['reachable']);
        Process::assertNothingRan();
    }

    public function test_dns_monitor_alerts_when_record_no_longer_matches(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dnsRecords')->with('example.com', 'A')->andReturn(['198.51.100.7']);
        });
        $monitor = Monitor::factory()->create([
            'type' => MonitorType::Dns,
            'url' => null,
            'host' => 'example.com',
            'dns_record_types' => ['A'],
            'dns_baseline' => ['A' => ['203.0.113.10']],
            'last_probe_method' => 'dns:ok',
        ]);

        app(MonitorCheckRunner::class)->run($monitor);

        $incident = MonitorIncident::sole();
        $this->assertSame('dns', $incident->root_cause_type);
        $this->assertSame('A changed: 203.0.113.10 → 198.51.100.7', $incident->root_cause);
        $this->assertSame('A: 198.51.100.7', $incident->response_body);
        $this->assertSame(['A' => ['203.0.113.10']], $monitor->refresh()->dns_baseline);
    }

    public function test_dns_watches_several_record_types_and_learns_their_baseline(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dnsRecords')->with('daffodilvarsity.edu.bd', 'A')->andReturn(['13.235.168.200', '13.203.132.7']);
            $mock->shouldReceive('dnsRecords')->with('daffodilvarsity.edu.bd', 'MX')->andReturn(['aspmx.l.google.com', 'alt1.aspmx.l.google.com']);
        });
        $monitor = Monitor::factory()->create(['type' => MonitorType::Dns, 'url' => null, 'host' => 'daffodilvarsity.edu.bd', 'dns_record_types' => ['A', 'MX']]);

        $check = app(MonitorCheckRunner::class)->run($monitor);

        $monitor->refresh();
        $this->assertSame(MonitorStatus::Up, $check->status);
        $this->assertSame('dns:ok', $monitor->last_probe_method);
        $this->assertSame([
            'A' => ['13.203.132.7', '13.235.168.200'],
            'MX' => ['alt1.aspmx.l.google.com', 'aspmx.l.google.com'],
        ], $monitor->dns_baseline);
        $this->assertSame('A/MX daffodilvarsity.edu.bd', $monitor->targetLabel());
    }

    public function test_dns_record_that_never_existed_is_misconfigured_not_an_outage(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dnsRecords')->with('daffodilvarsity.edu.bd', 'CNAME')->andReturn([]);
        });
        $monitor = Monitor::factory()->create(['type' => MonitorType::Dns, 'url' => null, 'host' => 'daffodilvarsity.edu.bd', 'dns_record_type' => 'CNAME']);

        $check = app(MonitorCheckRunner::class)->run($monitor);

        $monitor->refresh();
        $this->assertSame(MonitorStatus::Up, $check->status);
        $this->assertSame(0, MonitorIncident::count());
        $this->assertTrue($monitor->isInconclusive());
        $this->assertSame('Unknown', $monitor->headlineMetric());
    }

    public function test_dns_resolver_failure_is_inconclusive(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dnsRecords')->andReturn(null);
        });
        $monitor = Monitor::factory()->create(['type' => MonitorType::Dns, 'url' => null, 'host' => 'example.com', 'last_probe_method' => 'dns:ok']);

        $check = app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame(MonitorStatus::Up, $check->status);
        $this->assertSame('dns:inconclusive', $monitor->refresh()->last_probe_method);
        $this->assertSame(0, MonitorIncident::count());
    }

    public function test_dns_record_that_disappears_after_being_seen_opens_an_incident(): void
    {
        $records = [['198.51.100.7'], []];
        $this->mock(NetworkProbe::class, function (MockInterface $mock) use (&$records): void {
            $mock->shouldReceive('dnsRecords')->andReturnUsing(function () use (&$records): array {
                return count($records) > 1 ? array_shift($records) : $records[0];
            });
        });
        $monitor = Monitor::factory()->create(['type' => MonitorType::Dns, 'url' => null, 'host' => 'example.com', 'dns_record_type' => 'A']);
        $runner = app(MonitorCheckRunner::class);

        $runner->run($monitor);
        $this->assertSame('dns:ok', $monitor->refresh()->last_probe_method);

        $runner->run($monitor);

        $this->assertSame(MonitorStatus::Down, $monitor->refresh()->status);
        $this->assertSame('A record removed (was 198.51.100.7)', MonitorIncident::sole()->root_cause);
    }

    public function test_changing_dns_settings_resets_the_baseline(): void
    {
        $monitor = Monitor::factory()->create([
            'type' => MonitorType::Dns,
            'url' => null,
            'host' => 'example.com',
            'dns_baseline' => ['A' => ['93.184.216.34']],
            'last_probe_method' => 'dns:ok',
            'last_checked_at' => now(),
        ]);

        $monitor->update(['dns_record_types' => ['A', 'MX']]);

        $monitor->refresh();
        $this->assertNull($monitor->dns_baseline);
        $this->assertNull($monitor->last_probe_method);
        $this->assertNull($monitor->last_checked_at);
    }

    public function test_setup_advisor_explains_a_cname_on_a_root_domain(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dnsRecords')->with('daffodilvarsity.edu.bd', 'CNAME')->andReturn([]);
            $mock->shouldReceive('dnsRecords')->with('daffodilvarsity.edu.bd', 'A')->andReturn(['13.235.168.200', '13.203.132.7']);
            $mock->shouldReceive('dnsRecords')->with('daffodilvarsity.edu.bd', 'AAAA')->andReturn([]);
            $mock->shouldReceive('dnsRecords')->with('daffodilvarsity.edu.bd', 'MX')->andReturn(['aspmx.l.google.com']);
        });
        $monitor = Monitor::factory()->create(['type' => MonitorType::Dns, 'url' => null, 'host' => 'daffodilvarsity.edu.bd', 'dns_record_type' => 'CNAME']);

        $advice = app(MonitorSetupAdvisor::class)->adviseFor($monitor);

        $this->assertSame('daffodilvarsity.edu.bd has no CNAME record', $advice[0]['title']);
        $this->assertSame(
            'It does have A (13.235.168.200, 13.203.132.7), MX (aspmx.l.google.com). A root domain cannot have a CNAME — untick CNAME. Untick the missing types, otherwise this check stays "Unknown".',
            $advice[0]['body'],
        );
    }

    public function test_load_balancer_ip_rotation_is_learned_but_a_full_move_alerts(): void
    {
        $answers = [['13.235.168.200', '3.6.69.104'], ['198.51.100.1']];
        $this->mock(NetworkProbe::class, function (MockInterface $mock) use (&$answers): void {
            $mock->shouldReceive('dnsRecords')->andReturnUsing(function () use (&$answers): array {
                return count($answers) > 1 ? array_shift($answers) : $answers[0];
            });
        });
        $monitor = Monitor::factory()->create([
            'type' => MonitorType::Dns,
            'url' => null,
            'host' => 'daffodilvarsity.edu.bd',
            'dns_record_types' => ['A'],
            'dns_baseline' => ['A' => ['13.203.132.7', '13.235.168.200']],
            'last_probe_method' => 'dns:ok',
        ]);
        $runner = app(MonitorCheckRunner::class);

        $rotation = $runner->run($monitor);

        $this->assertSame(MonitorStatus::Up, $rotation->status);
        $this->assertSame(['A' => ['13.203.132.7', '13.235.168.200', '3.6.69.104']], $monitor->refresh()->dns_baseline);

        $runner->run($monitor);

        $this->assertSame(MonitorStatus::Down, $monitor->refresh()->status);
        $this->assertStringStartsWith('A changed: 13.203.132.7, 13.235.168.200, 3.6.69.104 → 198.51.100.1', MonitorIncident::sole()->root_cause);
    }

    public function test_dns_records_back_at_baseline_recover_the_monitor(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dnsRecords')->with('example.com', 'MX')->andReturn(['mx.example.com']);
        });
        $monitor = Monitor::factory()->down()->create([
            'type' => MonitorType::Dns,
            'url' => null,
            'host' => 'example.com',
            'dns_record_types' => ['MX'],
            'dns_baseline' => ['MX' => ['mx.example.com']],
            'last_probe_method' => 'dns:ok',
        ]);
        $incident = MonitorIncident::factory()->for($monitor)->create(['root_cause' => 'MX changed: mx.example.com → mail.evil.test']);

        app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame(MonitorStatus::Up, $monitor->refresh()->status);
        $this->assertSame(MonitorIncidentStatus::Resolved, $incident->refresh()->status);
    }

    public function test_accept_current_dns_makes_the_changed_records_the_new_baseline(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dnsRecords')->with('acme.example', 'A')->andReturn(['93.184.216.99']);
        });
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $user->id, 'billing_currency' => 'BDT', 'timezone' => 'UTC']);
        $workspace->members()->attach($user->id, ['role' => 'owner']);
        $monitor = Monitor::factory()->down()->create([
            'workspace_id' => $workspace->id,
            'type' => MonitorType::Dns,
            'url' => null,
            'host' => 'acme.example',
            'dns_record_types' => ['A'],
            'dns_baseline' => ['A' => ['93.184.216.34']],
            'last_probe_method' => 'dns:ok',
        ]);
        $incident = MonitorIncident::factory()->for($monitor)->create(['root_cause' => 'A changed: 93.184.216.34 → 93.184.216.99']);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($workspace);
        Filament::bootCurrentPanel();

        Livewire::test(ViewMonitor::class, ['record' => $monitor->id])
            ->callAction('acceptDnsBaseline')
            ->assertNotified('DNS baseline updated');

        $monitor->refresh();
        $this->assertSame(['A' => ['93.184.216.99']], $monitor->dns_baseline);
        $this->assertSame(MonitorStatus::Up, $monitor->status);
        $this->assertSame(MonitorIncidentStatus::Resolved, $incident->refresh()->status);
    }

    public function test_ssl_monitor_goes_down_inside_the_expiry_threshold(): void
    {
        $this->freezeTime();
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->with('shop.test')->andReturn(['93.184.216.34']);
            $mock->shouldReceive('sslCertificate')->with('shop.test', 443, 10, '93.184.216.34')->andReturn([
                'expires_at' => CarbonImmutable::now('UTC')->addDays(5),
                'issuer' => "Let's Encrypt",
                'subject' => 'shop.test',
                'error' => null,
            ]);
        });
        $monitor = Monitor::factory()->create(['type' => MonitorType::Ssl, 'url' => null, 'host' => 'shop.test', 'expiry_threshold_days' => 14]);

        app(MonitorCheckRunner::class)->run($monitor);

        $incident = MonitorIncident::sole();
        $this->assertSame('ssl', $incident->root_cause_type);
        $this->assertStringStartsWith('SSL certificate expires in 5 days', $incident->root_cause);
    }

    public function test_ssl_monitor_is_up_when_certificate_is_far_from_expiry(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->andReturn(['93.184.216.34']);
            $mock->shouldReceive('sslCertificate')->andReturn([
                'expires_at' => CarbonImmutable::now('UTC')->addDays(60),
                'issuer' => "Let's Encrypt",
                'subject' => 'shop.test',
                'error' => null,
            ]);
        });
        $monitor = Monitor::factory()->create(['type' => MonitorType::Ssl, 'url' => null, 'host' => 'shop.test']);

        $this->assertSame(MonitorStatus::Up, app(MonitorCheckRunner::class)->run($monitor)->status);
    }

    public function test_domain_monitor_reads_expiry_over_rdap(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake(['https://rdap.org/domain/example.com' => Http::response([
            'events' => [
                ['eventAction' => 'registration', 'eventDate' => '1995-08-14T04:00:00Z'],
                ['eventAction' => 'expiration', 'eventDate' => now()->addDays(3)->addHours(2)->toIso8601String()],
            ],
        ])]);
        $monitor = Monitor::factory()->create(['type' => MonitorType::Domain, 'url' => null, 'host' => 'example.com', 'expiry_threshold_days' => 30]);

        app(MonitorCheckRunner::class)->run($monitor);

        $this->assertStringStartsWith('Domain registration expires in 3 days', MonitorIncident::sole()->root_cause);
    }

    public function test_registry_without_rdap_shows_unknown_instead_of_an_outage(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://rdap.org/domain/daffodilvarsity.edu.bd' => Http::response([], 404)]);
        $monitor = Monitor::factory()->create(['type' => MonitorType::Domain, 'url' => null, 'host' => 'daffodilvarsity.edu.bd']);

        $check = app(MonitorCheckRunner::class)->run($monitor);

        $monitor->refresh();
        $this->assertSame(MonitorStatus::Up, $check->status);
        $this->assertSame(0, MonitorIncident::count());
        $this->assertTrue($monitor->expiryIsUnknown());
        $this->assertSame('Unknown', $monitor->headlineMetric());
    }

    public function test_manual_expiry_date_is_used_without_calling_rdap(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'UTC'));
        Http::preventStrayRequests();
        $monitor = Monitor::factory()->create([
            'type' => MonitorType::Domain,
            'url' => null,
            'host' => 'daffodilvarsity.edu.bd',
            'expiry_threshold_days' => 30,
            'manual_expires_at' => '2026-11-30',
        ]);

        app(MonitorCheckRunner::class)->run($monitor);

        Http::assertNothingSent();
        $this->assertSame('manual', $monitor->refresh()->last_probe_method);
        $this->assertSame('Domain registration expires in 20 days (2026-11-30)', MonitorIncident::sole()->root_cause);
    }

    public function test_failed_lookup_keeps_using_the_last_known_date(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://rdap.org/domain/example.com' => Http::response([], 503)]);
        $monitor = Monitor::factory()->create([
            'type' => MonitorType::Domain,
            'url' => null,
            'host' => 'example.com',
            'expires_at' => now()->addYear(),
        ]);

        $check = app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame(MonitorStatus::Up, $check->status);
        $this->assertSame('cached', $monitor->refresh()->last_probe_method);
        $this->assertSame(0, MonitorIncident::count());
    }

    public function test_changing_the_manual_date_schedules_a_fresh_check(): void
    {
        $monitor = Monitor::factory()->create(['type' => MonitorType::Domain, 'url' => null, 'host' => 'example.bd', 'last_checked_at' => now()]);

        $monitor->update(['manual_expires_at' => '2027-01-31']);

        $this->assertNull($monitor->refresh()->last_checked_at);
    }

    public function test_heartbeat_token_is_generated_and_url_is_public(): void
    {
        $monitor = Monitor::factory()->create(['type' => MonitorType::Heartbeat, 'url' => null]);

        $this->assertSame(40, strlen($monitor->heartbeat_token));
        $this->assertSame(url("/heartbeat/{$monitor->heartbeat_token}"), $monitor->heartbeatUrl());
        $this->assertArrayNotHasKey('heartbeat_token', $monitor->toArray());
    }

    public function test_missed_heartbeat_opens_incident_and_ping_resolves_it(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->create([
            'type' => MonitorType::Heartbeat,
            'url' => null,
            'interval_seconds' => 3600,
            'heartbeat_grace_seconds' => 300,
            'status' => MonitorStatus::Up,
            'last_heartbeat_at' => now()->subMinutes(66),
        ]);

        $this->artisan('sitevaulta:check-monitors')->assertSuccessful();

        $incident = MonitorIncident::sole();
        $this->assertSame('heartbeat', $incident->root_cause_type);
        $this->assertSame(MonitorStatus::Down, $monitor->refresh()->status);

        $this->get("/heartbeat/{$monitor->heartbeat_token}")->assertOk()->assertSeeText('OK');

        $this->assertSame(MonitorStatus::Up, $monitor->refresh()->status);
        $this->assertSame(MonitorIncidentStatus::Resolved, $incident->refresh()->status);
        $this->assertSame(now()->toDateTimeString(), $monitor->last_heartbeat_at->toDateTimeString());
    }

    public function test_new_heartbeat_monitor_stays_pending_until_first_ping_is_overdue(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->create(['type' => MonitorType::Heartbeat, 'url' => null, 'interval_seconds' => 3600]);

        $this->artisan('sitevaulta:check-monitors')->expectsOutput('Queued 0 due monitors');

        $this->assertSame(MonitorStatus::Pending, $monitor->refresh()->status);
    }

    public function test_heartbeat_endpoint_accepts_post_without_csrf_and_rejects_unknown_tokens(): void
    {
        $monitor = Monitor::factory()->create(['type' => MonitorType::Heartbeat, 'url' => null]);

        $this->post("/heartbeat/{$monitor->heartbeat_token}")->assertOk();
        $this->get('/heartbeat/'.str_repeat('a', 40))->assertNotFound();
        $this->assertNotNull($monitor->refresh()->last_heartbeat_at);
    }
}
