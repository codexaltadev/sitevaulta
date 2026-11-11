<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\MonitorType;
use App\Enums\PaymentGateway;
use App\Enums\SubscriptionStatus;
use App\Filament\App\Resources\Monitors\MonitorResource;
use App\Filament\App\Resources\Monitors\Pages\CreateMonitor;
use App\Filament\App\Resources\Monitors\Pages\EditMonitor;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Monitoring\MonitorChildChecksSync;
use App\Services\Monitoring\NetworkProbe;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class MonitorChildChecksTest extends TestCase
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

    /**
     * @param  list<string>|null  $allowedTypes
     */
    private function subscribeToPlan(int $maxMonitors = 10, ?array $allowedTypes = null): void
    {
        $plan = Plan::create([
            'code' => 'test-'.uniqid(),
            'name' => 'Test Plan',
            'max_monitors' => $maxMonitors,
            'min_monitor_interval_seconds' => 60,
            'allowed_monitor_types' => $allowedTypes,
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

    private function bootAppPanelForWorkspace(): void
    {
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->workspace);
        Filament::bootCurrentPanel();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function httpFormData(array $overrides = []): array
    {
        return [
            'type' => 'http',
            'name' => 'daffodilvarsity.edu.bd',
            'url' => 'https://www.daffodilvarsity.edu.bd/',
            'interval_seconds' => 300,
            'timeout_seconds' => 30,
            'alert_delay_minutes' => 5,
            'alert_repeat_minutes' => 0,
            ...$overrides,
        ];
    }

    public function test_ticking_extra_checks_creates_child_monitors_for_the_same_target(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->andReturn(['13.235.168.200']);
            $mock->shouldReceive('ping')->with('www.daffodilvarsity.edu.bd', 2)->andReturn(['reachable' => false, 'latency_ms' => null, 'error' => 'Host unreachable (no ping reply)']);
            $mock->shouldReceive('tcpConnect')->with('www.daffodilvarsity.edu.bd', 443, 3)->andReturn(['connected' => true, 'latency_ms' => 25]);
        });
        $this->subscribeToPlan();
        $this->bootAppPanelForWorkspace();

        Livewire::test(CreateMonitor::class)
            ->fillForm($this->httpFormData([
                'extra_checks' => ['ping', 'ssl', 'domain'],
                'extras' => [
                    'ssl' => ['expiry_threshold_days' => 21],
                    'domain' => ['host' => 'daffodilvarsity.edu.bd', 'expiry_threshold_days' => 45],
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('www.daffodilvarsity.edu.bd blocks ping (firewall)');

        $parent = Monitor::whereNull('parent_id')->sole();
        $children = $parent->children()->get()->keyBy(fn (Monitor $child): string => $child->type->value);

        $this->assertSame(['domain', 'ping', 'ssl'], $children->keys()->sort()->values()->all());
        $this->assertSame('www.daffodilvarsity.edu.bd', $children['ping']->host);
        $this->assertSame('www.daffodilvarsity.edu.bd', $children['ssl']->host);
        $this->assertSame(21, $children['ssl']->expiry_threshold_days);
        $this->assertSame(86400, $children['ssl']->interval_seconds);
        $this->assertSame('daffodilvarsity.edu.bd', $children['domain']->host);
        $this->assertSame(45, $children['domain']->expiry_threshold_days);
        $this->assertSame(5, $children['ping']->alert_delay_minutes);
        $this->assertSame('daffodilvarsity.edu.bd · SSL', $children['ssl']->name);
        $this->assertTrue($children->every(fn (Monitor $child): bool => $child->workspace_id === $this->workspace->id));
    }

    public function test_edit_form_shows_and_syncs_extra_checks(): void
    {
        $this->subscribeToPlan();
        $parent = Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://shop.test/']);
        $parent->children()->create(['workspace_id' => $this->workspace->id, 'type' => MonitorType::Ping, 'name' => 'shop · Ping', 'host' => 'shop.test']);
        $this->bootAppPanelForWorkspace();

        Livewire::test(EditMonitor::class, ['record' => $parent->id])
            ->assertFormSet(['extra_checks' => ['ping']])
            ->fillForm(['extra_checks' => ['ssl'], 'alert_delay_minutes' => 10])
            ->call('save')
            ->assertHasNoFormErrors();

        $children = $parent->children()->get();
        $this->assertSame([MonitorType::Ssl], $children->pluck('type')->all());
        $this->assertSame(10, $children->first()->alert_delay_minutes);
    }

    public function test_extra_checks_count_against_the_monitor_quota(): void
    {
        $this->subscribeToPlan(maxMonitors: 2);
        $this->bootAppPanelForWorkspace();

        Livewire::test(CreateMonitor::class)
            ->fillForm($this->httpFormData(['extra_checks' => ['ping', 'ssl']]))
            ->call('create')
            ->assertNotified('Monitor Quota Reached or Subscription Expired');

        $this->assertSame(0, Monitor::count());
    }

    public function test_plan_locked_types_are_rejected_by_the_server(): void
    {
        $this->subscribeToPlan(allowedTypes: ['http', 'keyword', 'ping', 'port']);
        $this->bootAppPanelForWorkspace();

        Livewire::test(CreateMonitor::class)
            ->fillForm($this->httpFormData(['extra_checks' => ['ssl']]))
            ->call('create')
            ->assertHasFormErrors(['extra_checks']);

        Livewire::test(CreateMonitor::class)
            ->fillForm($this->httpFormData(['type' => 'heartbeat', 'url' => null]))
            ->call('create')
            ->assertHasFormErrors(['type']);

        $this->assertSame(0, Monitor::count());
    }

    public function test_list_shows_parent_with_child_pills_and_detail_reports_child_incidents(): void
    {
        $this->subscribeToPlan();
        $parent = Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'name' => 'daffodilvarsity.edu.bd']);
        $ssl = $parent->children()->create([
            'workspace_id' => $this->workspace->id,
            'type' => MonitorType::Ssl,
            'name' => 'daffodilvarsity.edu.bd · SSL',
            'host' => 'daffodilvarsity.edu.bd',
            'status' => 'up',
            'expires_at' => now()->addDays(45)->addHour(),
        ]);
        $domain = $parent->children()->create([
            'workspace_id' => $this->workspace->id,
            'type' => MonitorType::Domain,
            'name' => 'daffodilvarsity.edu.bd · Domain',
            'host' => 'daffodilvarsity.edu.bd',
            'status' => 'down',
            'last_error' => 'Domain registration expires in 5 days',
        ]);
        MonitorIncident::factory()->for($domain)->create(['root_cause' => 'Domain registration expires in 5 days']);
        $this->actingAs($this->user);

        $this->get("/app/{$this->workspace->slug}/monitors")
            ->assertOk()
            ->assertSee('SSL 45d left')
            ->assertSee('DOMAIN Down')
            ->assertDontSee('daffodilvarsity.edu.bd · SSL');

        $this->get("/app/{$this->workspace->slug}/monitors/{$parent->id}")
            ->assertOk()
            ->assertSeeInOrder(['Checks for this target', 'SSL certificate expiry', '45d left', 'Domain expiry', 'Down'])
            ->assertSeeInOrder(['Latest incidents', 'Domain registration expires in 5 days', 'Domain expiry check']);

        $this->get("/app/{$this->workspace->slug}/monitors/{$ssl->id}")
            ->assertOk()
            ->assertSeeInOrder(['Part of', 'daffodilvarsity.edu.bd']);
    }

    public function test_editing_an_extra_check_opens_its_main_monitor(): void
    {
        $this->subscribeToPlan();
        $parent = Monitor::factory()->create(['workspace_id' => $this->workspace->id]);
        $ssl = $parent->children()->create(['workspace_id' => $this->workspace->id, 'type' => MonitorType::Ssl, 'name' => 'SSL', 'host' => 'shop.test']);
        $this->bootAppPanelForWorkspace();

        Livewire::test(EditMonitor::class, ['record' => $ssl->id])
            ->assertNotified('SSL certificate expiry is an extra check of '.$parent->name)
            ->assertRedirect(MonitorResource::getUrl('edit', ['record' => $parent]));
    }

    public function test_edit_page_shows_the_type_as_fixed_and_ignores_a_forged_type_change(): void
    {
        $this->subscribeToPlan();
        $parent = Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://shop.test/']);
        $this->actingAs($this->user);

        $this->get("/app/{$this->workspace->slug}/monitors/{$parent->id}/edit")
            ->assertOk()
            ->assertSee('The type is fixed after creation.')
            ->assertDontSee('Checks that a website or API answers');

        $this->bootAppPanelForWorkspace();
        Livewire::test(EditMonitor::class, ['record' => $parent->id])
            ->set('data.type', 'ping')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(MonitorType::Http, $parent->refresh()->type);
    }

    public function test_an_extra_check_can_never_own_extra_checks(): void
    {
        $parent = Monitor::factory()->create(['workspace_id' => $this->workspace->id]);
        $ssl = $parent->children()->create(['workspace_id' => $this->workspace->id, 'type' => MonitorType::Ssl, 'name' => 'SSL', 'host' => 'shop.test']);

        app(MonitorChildChecksSync::class)->sync($ssl, ['ping', 'domain'], []);

        $this->assertSame(0, $ssl->children()->count());
    }

    public function test_extra_check_detail_links_back_to_its_main_monitor(): void
    {
        $this->subscribeToPlan();
        $parent = Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'daffodilvarsity.edu.bd']);
        $ssl = $parent->children()->create(['workspace_id' => $this->workspace->id, 'type' => MonitorType::Ssl, 'name' => 'daffodilvarsity.edu.bd · SSL', 'host' => 'daffodilvarsity.edu.bd']);
        $this->actingAs($this->user);

        $this->get("/app/{$this->workspace->slug}/monitors/{$ssl->id}")
            ->assertOk()
            ->assertSee('Edit in main monitor')
            ->assertDontSee('Checks for this target')
            ->assertSee("/monitors/{$parent->id}/edit", false);
    }

    public function test_deleting_the_parent_removes_its_extra_checks(): void
    {
        $parent = Monitor::factory()->create(['workspace_id' => $this->workspace->id]);
        $child = $parent->children()->create(['workspace_id' => $this->workspace->id, 'type' => MonitorType::Ping, 'name' => 'Ping', 'host' => 'shop.test']);

        $parent->delete();

        $this->assertModelMissing($child);
    }
}
