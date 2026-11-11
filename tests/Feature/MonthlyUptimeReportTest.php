<?php

namespace Tests\Feature;

use App\Enums\MonitorType;
use App\Filament\App\Resources\Monitors\Pages\ListMonitors;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Monitoring\MonthlyUptimeReport;
use App\Services\Settings\WorkspaceSettingsService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MonthlyUptimeReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Monitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'UTC'));
        $this->user = User::factory()->create(['email' => 'owner@example.com']);
        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $this->user->id, 'billing_currency' => 'BDT', 'timezone' => 'UTC', 'email_credits_balance' => 10]);
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);
        $this->monitor = Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'name' => 'Shop', 'url' => 'https://shop.test/', 'created_at' => '2026-09-01']);

        // October: 3 checks (one failed), a 10-minute outage, and one running from Oct 31 23:50 into November.
        MonitorCheck::factory()->for($this->monitor)->create(['checked_at' => '2026-10-05 10:00', 'response_time_ms' => 200]);
        MonitorCheck::factory()->for($this->monitor)->create(['checked_at' => '2026-10-06 10:00', 'response_time_ms' => 400]);
        MonitorCheck::factory()->for($this->monitor)->failed()->create(['checked_at' => '2026-10-07 10:00']);
        MonitorIncident::factory()->for($this->monitor)->resolved(600)->create(['started_at' => CarbonImmutable::parse('2026-10-07 10:00', 'UTC')]);
        MonitorIncident::factory()->for($this->monitor)->resolved(1200)->create(['started_at' => CarbonImmutable::parse('2026-10-31 23:50', 'UTC')]);
    }

    public function test_the_report_counts_the_month_only(): void
    {
        $report = app(MonthlyUptimeReport::class)->build($this->workspace, CarbonImmutable::parse('2026-10-01', 'UTC'));
        $row = $report['monitors'][0];

        $this->assertSame('Shop', $row['monitor']->name);
        $this->assertSame(66.667, $row['uptime_percent']);
        $this->assertSame(2, $row['incidents']);
        $this->assertSame(600 + 600, $row['downtime_seconds'], 'Only the 10 minutes of the second outage that fall in October.');
        $this->assertSame(600, $row['longest_outage_seconds']);
        $this->assertSame(300, $row['average_response_ms']);
        $this->assertSame(66.667, $report['overall_uptime_percent']);
    }

    public function test_heartbeats_and_monitors_created_later_are_left_out(): void
    {
        Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'type' => MonitorType::Heartbeat, 'url' => null, 'created_at' => '2026-09-01']);
        Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://new.test/', 'created_at' => '2026-11-02']);

        $report = app(MonthlyUptimeReport::class)->build($this->workspace, CarbonImmutable::parse('2026-10-01', 'UTC'));

        $this->assertSame(['Shop'], array_map(fn (array $row): string => $row['monitor']->name, $report['monitors']));
    }

    public function test_the_report_is_emailed_once_per_month_and_can_be_turned_off(): void
    {
        $this->artisan('sitevaulta:send-monthly-uptime-reports')->expectsOutput('Sent 1 monthly uptime reports for 2026-10');
        $this->assertSame('2026-10', WorkspaceSettingsService::get($this->workspace, 'reports', 'monthly_uptime_report_last_sent'));

        $this->artisan('sitevaulta:send-monthly-uptime-reports')->expectsOutput('Sent 0 monthly uptime reports for 2026-10');

        WorkspaceSettingsService::set($this->workspace, 'reports', 'monthly_uptime_report', false, 'boolean');
        $this->artisan('sitevaulta:send-monthly-uptime-reports', ['--force' => true])->expectsOutput('Sent 0 monthly uptime reports for 2026-10');
    }

    public function test_the_pdf_can_be_downloaded_from_the_monitors_page(): void
    {
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->workspace);
        Filament::bootCurrentPanel();

        Livewire::test(ListMonitors::class)
            ->callAction('monthlyReport', data: ['month' => '2026-10'])
            ->assertFileDownloaded('uptime-report-2026-10.pdf');

        $this->assertArrayHasKey('2026-11', ListMonitors::reportMonthOptions());
        $this->assertSame('November 2026 (so far)', ListMonitors::reportMonthOptions()['2026-11']);
    }
}
