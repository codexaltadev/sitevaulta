<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\PaymentGateway;
use App\Enums\SubscriptionStatus;
use App\Filament\App\Resources\StatusPages\Pages\CreateStatusPage;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\Plan;
use App\Models\StatusPage;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StatusPageTest extends TestCase
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
            'timezone' => 'UTC',
        ]);
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);
    }

    private function subscribeToPlan(int $maxStatusPages): void
    {
        $plan = Plan::create([
            'code' => "test-{$maxStatusPages}",
            'name' => 'Test Plan',
            'max_status_pages' => $maxStatusPages,
            'features' => [],
        ]);

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

    private function bootAppPanelForWorkspace(): void
    {
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->workspace);
        Filament::bootCurrentPanel();
    }

    public function test_public_page_shows_health_without_leaking_urls_or_error_details(): void
    {
        $this->freezeTime();
        $down = Monitor::factory()->down()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Checkout API',
            'url' => 'https://internal-api.secret.test/health',
            'last_error' => 'Connection refused to 10.0.0.12',
        ]);
        $up = Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'name' => 'Website']);
        MonitorCheck::factory()->for($up)->create(['checked_at' => now()->subDays(2)]);
        MonitorIncident::factory()->for($down)->create(['root_cause' => 'Connection refused to 10.0.0.12']);
        $page = StatusPage::factory()->create(['workspace_id' => $this->workspace->id, 'slug' => 'acme', 'name' => 'Acme Status']);
        $page->monitors()->attach([$down->id, $up->id]);

        $this->get('/status/acme')
            ->assertOk()
            ->assertSee('Acme Status')
            ->assertSee('Some systems are down')
            ->assertSeeInOrder(['Checkout API', 'Down', 'Website', 'Operational', '100% uptime'])
            ->assertSee('Ongoing outage')
            ->assertDontSee('internal-api.secret.test')
            ->assertDontSee('10.0.0.12');
    }

    public function test_maintenance_shows_banner_instead_of_outage(): void
    {
        $monitor = Monitor::factory()->down()->create(['workspace_id' => $this->workspace->id]);
        MaintenanceWindow::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'DB upgrade'])->monitors()->attach($monitor);
        StatusPage::factory()->create(['workspace_id' => $this->workspace->id, 'slug' => 'acme'])->monitors()->attach($monitor);

        $this->get('/status/acme')
            ->assertOk()
            ->assertSee('Scheduled maintenance in progress')
            ->assertSee('DB upgrade')
            ->assertDontSee('Some systems are down');
    }

    public function test_unpublished_or_unknown_page_is_not_found(): void
    {
        StatusPage::factory()->create(['workspace_id' => $this->workspace->id, 'slug' => 'hidden', 'is_published' => false]);

        $this->get('/status/hidden')->assertNotFound();
        $this->get('/status/does-not-exist')->assertNotFound();
    }

    public function test_password_protected_page_requires_the_right_password(): void
    {
        StatusPage::factory()->passwordProtected('open-sesame')->create([
            'workspace_id' => $this->workspace->id,
            'slug' => 'private',
            'name' => 'Private Status',
        ]);

        $this->get('/status/private')->assertOk()->assertSee('This status page is private')->assertDontSee('All systems operational');

        $this->from('/status/private')
            ->post('/status/private/unlock', ['password' => 'wrong'])
            ->assertRedirect('/status/private')
            ->assertSessionHasErrors(['password' => 'That password is not correct.']);

        $this->post('/status/private/unlock', ['password' => 'open-sesame'])->assertRedirect('/status/private');
        $this->get('/status/private')->assertOk()->assertSee('All systems operational');
    }

    public function test_status_page_password_is_stored_hashed(): void
    {
        $page = StatusPage::factory()->passwordProtected('open-sesame')->create(['workspace_id' => $this->workspace->id]);

        $this->assertNotSame('open-sesame', $page->getRawOriginal('password'));
    }

    public function test_status_page_quota_blocks_creation(): void
    {
        $this->subscribeToPlan(maxStatusPages: 1);
        $monitor = Monitor::factory()->create(['workspace_id' => $this->workspace->id]);
        StatusPage::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->bootAppPanelForWorkspace();

        Livewire::test(CreateStatusPage::class)
            ->fillForm(['name' => 'Second', 'slug' => 'second-page', 'monitors' => [$monitor->id]])
            ->call('create')
            ->assertNotified('Status Page Quota Reached or Subscription Expired');

        $this->assertSame(1, $this->workspace->statusPages()->count());
    }

    public function test_status_page_cannot_include_another_workspaces_monitor(): void
    {
        $this->subscribeToPlan(maxStatusPages: 5);
        $foreignMonitor = Monitor::factory()->create();
        $this->bootAppPanelForWorkspace();

        Livewire::test(CreateStatusPage::class)
            ->fillForm(['name' => 'Sneaky', 'slug' => 'sneaky', 'monitors' => [$foreignMonitor->id]])
            ->call('create')
            ->assertHasFormErrors(['monitors']);

        $this->assertSame(0, StatusPage::count());
    }

    public function test_status_page_is_created_for_own_monitors(): void
    {
        $this->subscribeToPlan(maxStatusPages: 5);
        $monitor = Monitor::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->bootAppPanelForWorkspace();

        Livewire::test(CreateStatusPage::class)
            ->fillForm(['name' => 'Acme Status', 'slug' => 'acme-status', 'monitors' => [$monitor->id], 'password' => 'secret-123'])
            ->call('create')
            ->assertHasNoFormErrors();

        $page = StatusPage::sole();
        $this->assertSame($this->workspace->id, $page->workspace_id);
        $this->assertTrue($page->isPasswordProtected());
        $this->assertSame([$monitor->id], $page->monitors()->pluck('monitors.id')->all());
    }
}
