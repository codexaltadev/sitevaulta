<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Monitors\Pages\ViewMonitor;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\User;
use App\Models\VerifiedDomain;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Monitoring\MonitorUptimeStatistics;
use App\Services\Monitoring\NetworkProbe;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class MonitorDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Milton']);
        $this->workspace = Workspace::create([
            'name' => 'Milton Agency',
            'slug' => 'milton-agency',
            'owner_id' => $this->user->id,
            'billing_currency' => 'BDT',
            'timezone' => 'UTC',
        ]);
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);
    }

    private function bootAppPanelForWorkspace(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->workspace);
        Filament::bootCurrentPanel();
    }

    public function test_detail_page_shows_status_uptime_response_times_and_incidents(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $monitor = Monitor::factory()->down()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'shop.test',
            'created_at' => now()->subDays(10),
        ]);
        MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->subHours(2), 'response_time_ms' => 100]);
        MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->subHours(1), 'response_time_ms' => 300]);
        MonitorCheck::factory()->for($monitor)->failed()->create(['checked_at' => now()->subMinutes(10)]);
        MonitorIncident::factory()->for($monitor)->create([
            'root_cause' => 'DNS Resolving problem',
            'started_at' => now()->subMinutes(40),
        ]);

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/monitors/{$monitor->id}")
            ->assertOk()
            ->assertSee('shop.test')
            ->assertSeeInOrder(['Current status', 'Down', 'DNS Resolving problem'])
            ->assertSeeInOrder(['Last 7 days', '66.667%', '1 incident,', '40m 0s down'])
            ->assertSeeInOrder(['200 ms', 'Average', '100 ms', 'Minimum', '300 ms', 'Maximum'])
            ->assertSeeInOrder(['Latest incidents', 'Ongoing', 'DNS Resolving problem', '40m 0s']);
    }

    public function test_new_monitor_says_how_long_it_has_been_monitored(): void
    {
        $this->travelTo(now()->setTime(12, 30));
        $monitor = Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'created_at' => now()->subMinutes(25)]);
        MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->subMinutes(25)]);
        MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->subMinutes(5)]);

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/monitors/{$monitor->id}")
            ->assertOk()
            ->assertSeeInOrder(['Last 24 hours', '100%'])
            ->assertSee('Monitoring for 25m 0s · 2 checks — striped bars are hours before monitoring started')
            ->assertSee('Not monitored yet / no checks')
            ->assertSee('since '.now()->subMinutes(25)->format('M j, H:i'));
    }

    public function test_each_outage_is_drawn_where_it_happened_within_the_hour(): void
    {
        // Up 13:00–13:20, down 6 min, up 15 min, down 5 min, then up for the rest of the hour.
        $this->travelTo(now()->setTime(14, 50));
        $monitor = Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'created_at' => now()->subDay()]);
        MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->setTime(13, 10)]);
        MonitorCheck::factory()->for($monitor)->failed()->create(['checked_at' => now()->setTime(13, 20)]);
        MonitorIncident::factory()->for($monitor)->resolved(360)->create(['started_at' => now()->setTime(13, 20)]);
        MonitorIncident::factory()->for($monitor)->resolved(300)->create(['started_at' => now()->setTime(13, 41)]);

        $hours = app(MonitorUptimeStatistics::class)->hourlyBarsWithUptime([$monitor->id])[$monitor->id];
        $hour = $hours['hours'][22];

        $this->assertSame('down', $hours['bars'][22]);
        $this->assertSame(660, $hour['down_seconds']);
        $this->assertSame(81.7, $hour['up_percent']);
        $this->assertSame([[1200, 1560], [2460, 2760]], $hour['segments']);
        $this->assertSame([
            'style' => 'background: linear-gradient(to bottom, var(--sv-success) 0% 33.33%, var(--sv-danger) 33.33% 43.33%, var(--sv-success) 43.33% 68.33%, var(--sv-danger) 68.33% 76.67%, var(--sv-success) 76.67% 100%);',
            'label' => '81.7% up · down 11m 0s (13:20–13:26, 13:41–13:46)',
        ], ViewMonitor::hourBar('down', $hour, now()->setTime(13, 0)));

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/monitors/{$monitor->id}")
            ->assertOk()
            ->assertSee('81.7% up · down 11m 0s', false);
    }

    public function test_overlapping_incidents_are_counted_once_and_the_future_part_of_the_hour_is_faint(): void
    {
        $this->travelTo(now()->setTime(14, 30));
        $monitor = Monitor::factory()->down()->create(['workspace_id' => $this->workspace->id, 'created_at' => now()->subDay()]);
        MonitorCheck::factory()->for($monitor)->failed()->create(['checked_at' => now()->setTime(14, 10)]);
        MonitorIncident::factory()->for($monitor)->resolved(600)->create(['started_at' => now()->setTime(14, 0)]);
        MonitorIncident::factory()->for($monitor)->resolved(600)->create(['started_at' => now()->setTime(14, 5)]);

        $hour = app(MonitorUptimeStatistics::class)->hourlyBarsWithUptime([$monitor->id])[$monitor->id]['hours'][23];

        $this->assertSame([[0, 900]], $hour['segments']);
        $this->assertSame(900, $hour['down_seconds']);
        $this->assertSame(1800, $hour['elapsed_seconds']);
        $this->assertStringEndsWith('var(--sv-success) 25% 50%, var(--sv-border) 50% 100%);', ViewMonitor::hourBar('down', $hour)['style']);
    }

    public function test_detail_page_of_another_workspace_monitor_is_not_found(): void
    {
        $foreignMonitor = Monitor::factory()->create();

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/monitors/{$foreignMonitor->id}")
            ->assertNotFound();
    }

    public function test_seven_day_range_averages_checks_per_hour(): void
    {
        $this->travelTo(now()->setTime(12, 30));
        $monitor = Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id]);
        MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->subDays(3)->setTime(9, 5), 'response_time_ms' => 100]);
        MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->subDays(3)->setTime(9, 45), 'response_time_ms' => 300]);

        $series = app(MonitorUptimeStatistics::class)->responseTimeSeries($monitor, '7d');

        $this->assertCount(1, $series['points']);
        $this->assertSame(200, $series['points'][0]['ms']);
        $this->assertSame(now()->subDays(3)->setTime(9, 0)->getTimestamp(), $series['points'][0]['at']);
    }

    public function test_period_summary_counts_downtime_and_mtbf_inside_the_period_only(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->up()->create([
            'workspace_id' => $this->workspace->id,
            'created_at' => now()->subDays(30),
        ]);
        MonitorIncident::factory()->for($monitor)->resolved(600)->create(['started_at' => now()->subDays(2)]);
        MonitorIncident::factory()->for($monitor)->resolved(3600)->create(['started_at' => now()->subDays(20)]);

        $sevenDays = app(MonitorUptimeStatistics::class)->monitorPeriodSummary($monitor, 7);

        $this->assertSame(1, $sevenDays['incidents']);
        $this->assertSame(600, $sevenDays['downtime_seconds']);
        $this->assertSame(7 * 86400 - 600, $sevenDays['mtbf_seconds']);
    }

    public function test_ownership_panel_shows_verified_owner_for_a_verified_backup_website(): void
    {
        $this->verifyWebsiteOwnership(Website::create(['workspace_id' => $this->workspace->id, 'name' => 'Shop', 'url' => 'https://shop.test', 'uptime_monitoring_enabled' => false]));
        $monitor = Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://shop.test']);

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/monitors/{$monitor->id}")
            ->assertOk()
            ->assertSeeInOrder(['Ownership', '✓ Verified owner', 'shop.test — proven via verified website shop.test'])
            ->assertDontSee('Verify ownership');
    }

    public function test_unverified_monitor_can_start_and_complete_verification_from_its_detail_page(): void
    {
        $monitor = Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://api.shop.test/health']);
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dnsRecords')->andReturnUsing(fn (): array => [VerifiedDomain::sole()->token]);
        });
        $this->actingAs($this->user);
        $this->bootAppPanelForWorkspace();

        Livewire::test(ViewMonitor::class, ['record' => $monitor->id])
            ->assertSee('Verify ownership')
            ->call('startOwnershipVerification')
            ->assertSee('_sitevaulta-challenge.api.shop.test')
            ->assertSee(VerifiedDomain::sole()->token)
            ->assertSee('https://api.shop.test/.well-known/sitevaulta-verify.txt')
            ->assertSee('https://api.shop.test/sitevaulta-verify.txt')
            ->assertSee('Copied ✓', false)
            ->call('verifyOwnership')
            ->assertNotified('api.shop.test is verified')
            ->assertSee('✓ Verified owner');

        $this->assertSame('dns_txt', VerifiedDomain::sole()->verification_method);
    }

    public function test_test_notification_reaches_workspace_members(): void
    {
        $monitor = Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'name' => 'shop.test']);
        $this->actingAs($this->user);
        $this->bootAppPanelForWorkspace();

        Livewire::test(ViewMonitor::class, ['record' => $monitor->id])
            ->call('sendTestNotification')
            ->assertNotified('Test notification sent');

        $this->assertSame(1, $this->user->notifications()->count());
        $this->assertStringContainsString('shop.test', $this->user->notifications()->first()->data['title']);
    }
}
