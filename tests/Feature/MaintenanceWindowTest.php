<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Filament\App\Resources\MaintenanceWindows\Pages\CreateMaintenanceWindow;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Monitoring\MonitorCheckRunner;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MaintenanceWindowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::create([
            'name' => 'Milton Agency',
            'slug' => 'milton-agency',
            'owner_id' => $this->user->id,
            'billing_currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
        ]);
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);
    }

    public function test_one_time_window_is_in_progress_only_between_start_and_end(): void
    {
        $window = MaintenanceWindow::factory()->create([
            'workspace_id' => $this->workspace->id,
            'starts_at' => CarbonImmutable::parse('2026-11-15 20:00', 'UTC'),
            'duration_minutes' => 120,
        ]);

        $this->assertFalse($window->isInProgressAt(CarbonImmutable::parse('2026-11-15 19:59', 'UTC')));
        $this->assertTrue($window->isInProgressAt(CarbonImmutable::parse('2026-11-15 21:59', 'UTC')));
        $this->assertFalse($window->isInProgressAt(CarbonImmutable::parse('2026-11-15 22:00', 'UTC')));
    }

    public function test_weekly_window_uses_workspace_timezone_and_runs_past_midnight(): void
    {
        // Every Sunday 23:00 Dhaka time (UTC+6) for 2 hours → Sunday 17:00–19:00 UTC.
        $window = MaintenanceWindow::factory()->weekly([0], '23:00', 120)->create(['workspace_id' => $this->workspace->id]);

        $this->assertTrue($window->isInProgressAt(CarbonImmutable::parse('2026-11-15 17:30', 'UTC')));
        $this->assertTrue($window->isInProgressAt(CarbonImmutable::parse('2026-11-15 18:59', 'UTC')));
        $this->assertFalse($window->isInProgressAt(CarbonImmutable::parse('2026-11-15 19:00', 'UTC')));
        $this->assertFalse($window->isInProgressAt(CarbonImmutable::parse('2026-11-16 17:30', 'UTC')));
        $this->assertSame(
            '2026-11-22 17:00',
            $window->nextStartAfter(CarbonImmutable::parse('2026-11-15 19:00', 'UTC'))->format('Y-m-d H:i'),
        );
    }

    public function test_monthly_window_runs_on_its_day_only(): void
    {
        $window = MaintenanceWindow::factory()->monthly(1, '02:00', 60)->create(['workspace_id' => $this->workspace->id]);

        $this->assertTrue($window->isInProgressAt(CarbonImmutable::parse('2026-12-01 02:30', 'Asia/Dhaka')));
        $this->assertFalse($window->isInProgressAt(CarbonImmutable::parse('2026-12-02 02:30', 'Asia/Dhaka')));
    }

    public function test_paused_window_never_applies(): void
    {
        $window = MaintenanceWindow::factory()->create(['workspace_id' => $this->workspace->id, 'is_active' => false]);

        $this->assertFalse($window->isInProgressAt(now()));
        $this->assertNull($window->nextStartAfter(now()->subDay()));
    }

    public function test_failure_during_maintenance_records_nothing_and_opens_no_incident(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://shop.test/' => Http::response('down', 503)]);
        $monitor = Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://shop.test/']);
        MaintenanceWindow::factory()->create(['workspace_id' => $this->workspace->id])->monitors()->attach($monitor);

        app(MonitorCheckRunner::class)->run($monitor->load('maintenanceWindows'));

        $this->assertSame(MonitorStatus::Up, $monitor->refresh()->status);
        $this->assertSame(0, $monitor->checks()->count());
        $this->assertSame(0, $monitor->incidents()->count());
    }

    public function test_scheduler_skips_monitors_under_maintenance(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://free.test/' => Http::response('ok', 200)]);
        $inMaintenance = Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://busy.test/']);
        $free = Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://free.test/']);
        MaintenanceWindow::factory()->create(['workspace_id' => $this->workspace->id])->monitors()->attach($inMaintenance);

        $this->artisan('sitevaulta:check-monitors')
            ->expectsOutput('Checked 1 due monitors | Down: 0')
            ->assertSuccessful();

        $this->assertSame(0, $inMaintenance->checks()->count());
        $this->assertSame(1, $free->checks()->count());
    }

    public function test_weekly_window_can_be_created_for_own_monitors(): void
    {
        $monitor = Monitor::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->workspace);
        Filament::bootCurrentPanel();

        Livewire::test(CreateMaintenanceWindow::class)
            ->fillForm([
                'name' => 'Sunday updates',
                'schedule_type' => 'weekly',
                'start_time' => '02:00',
                'days_of_week' => [0],
                'duration_minutes' => 60,
                'monitors' => [$monitor->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $window = MaintenanceWindow::sole();
        $this->assertSame($this->workspace->id, $window->workspace_id);
        $this->assertSame('02:00', $window->start_time);
        $this->assertSame([$monitor->id], $window->monitors()->pluck('monitors.id')->all());
    }

    public function test_window_cannot_include_another_workspaces_monitor(): void
    {
        $foreignMonitor = Monitor::factory()->create();
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->workspace);
        Filament::bootCurrentPanel();

        Livewire::test(CreateMaintenanceWindow::class)
            ->fillForm([
                'name' => 'Sneaky',
                'schedule_type' => 'daily',
                'start_time' => '02:00',
                'duration_minutes' => 60,
                'monitors' => [$foreignMonitor->id],
            ])
            ->call('create')
            ->assertHasFormErrors(['monitors']);

        $this->assertSame(0, MaintenanceWindow::count());
    }

    public function test_maintenance_list_hides_other_workspaces_windows(): void
    {
        MaintenanceWindow::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Our window']);
        $otherWorkspace = Monitor::factory()->create()->workspace;
        MaintenanceWindow::factory()->create(['workspace_id' => $otherWorkspace->id, 'name' => 'Their window']);

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/maintenance")
            ->assertOk()
            ->assertSee('Our window')
            ->assertDontSee('Their window');
    }
}
