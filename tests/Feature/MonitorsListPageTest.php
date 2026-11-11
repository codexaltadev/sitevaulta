<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\MonitorStatus;
use App\Enums\PaymentGateway;
use App\Enums\SubscriptionStatus;
use App\Filament\App\Resources\Monitors\Pages\CreateMonitor;
use App\Filament\App\Resources\Monitors\Pages\EditMonitor;
use App\Filament\App\Resources\Monitors\Pages\ListMonitors;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MonitorsListPageTest extends TestCase
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
        $this->subscribeToPlan(maxMonitors: 10, minIntervalSeconds: 300);
    }

    /**
     * Mirrors a real /app request: the panel boot registers Filament's tenant scoping and ownership hooks.
     */
    private function bootAppPanelForWorkspace(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->workspace);
        Filament::bootCurrentPanel();
    }

    private function subscribeToPlan(int $maxMonitors, int $minIntervalSeconds): void
    {
        $plan = Plan::create([
            'code' => "test-{$maxMonitors}-{$minIntervalSeconds}",
            'name' => 'Test Plan',
            'max_monitors' => $maxMonitors,
            'min_monitor_interval_seconds' => $minIntervalSeconds,
            'features' => [],
        ]);

        $this->workspace->subscriptions()->delete();
        Subscription::create([
            'workspace_id' => $this->workspace->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Trialing,
            'billing_cycle' => BillingCycle::Trial,
            'gateway' => PaymentGateway::None,
            'trial_starts_at' => now()->utc(),
            'trial_ends_at' => now()->utc()->addDays(7),
        ]);
    }

    public function test_monitors_page_lists_down_monitors_first_with_status_summary(): void
    {
        $this->freezeTime();
        Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'name' => 'alpha-up.test']);
        $down = Monitor::factory()->down()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'zeta-down.test',
            'last_error' => 'DNS Resolving problem',
        ]);
        MonitorCheck::factory()->for($down)->failed()->create(['checked_at' => now()->subMinutes(10)]);
        MonitorCheck::factory()->for($down)->create(['checked_at' => now()->subHours(3)]);
        MonitorIncident::factory()->for($down)->create(['started_at' => now()->subMinutes(30)]);

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/monitors")
            ->assertOk()
            ->assertSeeInOrder(['zeta-down.test', 'DNS Resolving problem', 'alpha-up.test'])
            ->assertSeeInOrder(['Current status', '1', 'Down', '1', 'Up', '0', 'Paused'])
            ->assertSee('50%')
            ->assertSee('30m');
    }

    public function test_only_monitors_on_an_exactly_verified_host_get_an_ownership_badge(): void
    {
        $this->verifyWebsiteOwnership(Website::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Shop',
            'url' => 'https://shop.test',
            'domain' => 'shop.test',
            'status' => 'active',
            'uptime_monitoring_enabled' => false,
        ]));
        Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'name' => 'shop', 'url' => 'https://www.shop.test']);
        Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'name' => 'api', 'url' => 'https://api.shop.test']);
        Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'name' => 'stranger', 'url' => 'https://stranger.test']);

        $html = $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/monitors")
            ->assertOk()
            ->assertSee('Ownership proven via verified website shop.test')
            ->getContent();

        $this->assertSame(1, substr_count($html, '✓ VERIFIED OWNER'));
        $this->assertSame(2, substr_count($html, '>UNVERIFIED<'), 'api.shop.test and stranger.test need their own proof.');
        $this->assertStringContainsString('#ownership', $html);
    }

    public function test_row_actions_menu_offers_view_check_pause_edit_and_delete(): void
    {
        Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'name' => 'menu.test']);

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/monitors")
            ->assertOk()
            ->assertSee('Actions for menu.test')
            ->assertSeeInOrder(['View details', 'Check now', 'Pause', 'Edit', 'Delete']);
    }

    public function test_monitors_page_hides_other_workspaces_monitors(): void
    {
        Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'mine.test']);
        Monitor::factory()->create(['name' => 'someone-else.test']);

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/monitors")
            ->assertOk()
            ->assertSee('mine.test')
            ->assertDontSee('someone-else.test');
    }

    public function test_status_filter_and_search_narrow_the_list(): void
    {
        Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'name' => 'shop.test']);
        Monitor::factory()->down()->create(['workspace_id' => $this->workspace->id, 'name' => 'blog.test']);
        $this->actingAs($this->user);
        $this->bootAppPanelForWorkspace();

        Livewire::test(ListMonitors::class)
            ->set('statusFilter', 'down')
            ->assertSee('blog.test')
            ->assertDontSee('shop.test')
            ->set('statusFilter', 'all')
            ->set('search', 'shop')
            ->assertSee('shop.test')
            ->assertDontSee('blog.test');
    }

    public function test_pause_and_resume_change_monitor_status(): void
    {
        $monitor = Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id]);
        $this->actingAs($this->user);
        $this->bootAppPanelForWorkspace();

        $page = Livewire::test(ListMonitors::class)->call('pauseMonitor', $monitor->id);
        $this->assertSame(MonitorStatus::Paused, $monitor->refresh()->status);

        $page->call('resumeMonitor', $monitor->id);
        $this->assertSame(MonitorStatus::Pending, $monitor->refresh()->status);
        $this->assertNull($monitor->last_checked_at);
    }

    public function test_actions_cannot_touch_another_workspaces_monitor(): void
    {
        $foreignMonitor = Monitor::factory()->up()->create();
        $this->actingAs($this->user);
        $this->bootAppPanelForWorkspace();

        Livewire::test(ListMonitors::class)
            ->call('deleteMonitor', $foreignMonitor->id)
            ->assertNotFound();

        $this->assertModelExists($foreignMonitor);
    }

    public function test_creating_a_monitor_is_blocked_when_plan_limit_is_reached(): void
    {
        $this->subscribeToPlan(maxMonitors: 1, minIntervalSeconds: 300);
        Monitor::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->actingAs($this->user);
        $this->bootAppPanelForWorkspace();

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'http', 'name' => 'Second', 'url' => 'https://second.test/', 'interval_seconds' => 300, 'timeout_seconds' => 30])
            ->call('create')
            ->assertNotified('Monitor Quota Reached or Subscription Expired');

        $this->assertSame(1, $this->workspace->monitors()->count());
    }

    public function test_create_and_edit_pages_render_with_sections_and_summary(): void
    {
        $monitor = Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'name' => 'edit-me.test']);
        $this->actingAs($this->user);

        $this->get("/app/{$this->workspace->slug}/monitors/create")
            ->assertOk()
            ->assertSeeInOrder(['Monitor type', 'Extra checks for this target', 'Monitor interval', 'Notifications and alerts', 'Advanced settings', 'Summary'])
            ->assertSee('🔒 1m')
            ->assertSee('2 of 10 monitors (including this one, plus 1 per extra check)');

        $this->get("/app/{$this->workspace->slug}/monitors/{$monitor->id}/edit")
            ->assertOk()
            ->assertSee('edit-me.test');
    }

    public function test_edit_form_loads_the_saved_type_interval_and_timeout(): void
    {
        $monitor = Monitor::factory()->create([
            'workspace_id' => $this->workspace->id,
            'url' => 'https://daffodilvarsity.edu.bd/',
            'interval_seconds' => 600,
            'timeout_seconds' => 20,
        ]);
        $this->actingAs($this->user);
        $this->bootAppPanelForWorkspace();

        Livewire::test(EditMonitor::class, ['record' => $monitor->id])
            ->assertFormSet([
                'type' => 'http',
                'interval_seconds' => 600,
                'interval_step' => 3,
                'timeout_seconds' => 20,
            ]);
    }

    public function test_moving_the_interval_slider_sets_the_interval(): void
    {
        $this->actingAs($this->user);
        $this->bootAppPanelForWorkspace();

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'http', 'name' => 'Slider', 'url' => 'https://slider.test/', 'timeout_seconds' => 30])
            ->set('data.interval_step', 5)
            ->assertFormSet(['interval_seconds' => 3600])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('monitors', ['name' => 'Slider', 'interval_seconds' => 3600]);
    }

    public function test_monitor_interval_faster_than_plan_minimum_is_rejected(): void
    {
        $this->actingAs($this->user);
        $this->bootAppPanelForWorkspace();

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'http', 'name' => 'Fast', 'url' => 'https://fast.test/', 'interval_seconds' => 60, 'timeout_seconds' => 30])
            ->call('create')
            ->assertHasFormErrors(['interval_seconds']);

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'http', 'name' => 'Normal', 'url' => 'https://normal.test/', 'interval_seconds' => 300, 'timeout_seconds' => 30])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('monitors', ['workspace_id' => $this->workspace->id, 'name' => 'Normal', 'interval_seconds' => 300]);
        $this->assertDatabaseMissing('monitors', ['name' => 'Fast']);
    }

    public function test_import_stops_at_remaining_plan_slots(): void
    {
        $this->subscribeToPlan(maxMonitors: 1, minIntervalSeconds: 300);
        foreach (['one', 'two'] as $site) {
            Website::create(['workspace_id' => $this->workspace->id, 'name' => $site, 'url' => "https://{$site}.test", 'uptime_monitoring_enabled' => false]);
        }
        $this->actingAs($this->user);
        $this->bootAppPanelForWorkspace();

        Livewire::test(ListMonitors::class)
            ->call('importWebsites')
            ->assertNotified('Imported 1 monitor(s); 1 skipped');

        $this->assertSame(1, $this->workspace->monitors()->count());
    }

    public function test_import_creates_one_monitor_per_unmonitored_website(): void
    {
        $alreadyMonitored = Website::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Old Site',
            'url' => 'https://old.test',
            'uptime_monitoring_enabled' => false,
        ]);
        Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'website_id' => $alreadyMonitored->id]);
        $newWebsite = Website::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'New Site',
            'url' => 'https://new.test',
            'uptime_monitoring_enabled' => false,
        ]);
        $this->actingAs($this->user);
        $this->bootAppPanelForWorkspace();

        Livewire::test(ListMonitors::class)->call('importWebsites');

        $this->assertSame(2, $this->workspace->monitors()->count());
        $this->assertDatabaseHas('monitors', [
            'workspace_id' => $this->workspace->id,
            'website_id' => $newWebsite->id,
            'name' => 'New Site',
            'url' => 'https://new.test',
        ]);
    }
}
