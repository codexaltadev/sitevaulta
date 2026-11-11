<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\MonitorType;
use App\Enums\PaymentGateway;
use App\Enums\SubscriptionStatus;
use App\Filament\App\Resources\Monitors\Pages\CreateMonitor;
use App\Filament\App\Resources\Monitors\Pages\EditMonitor;
use App\Filament\App\Resources\Monitors\Pages\ListMonitors;
use App\Filament\App\Resources\Websites\Pages\CreateWebsite;
use App\Models\Monitor;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Monitoring\NetworkProbe;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DuplicateTargetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $this->user->id, 'billing_currency' => 'BDT', 'timezone' => 'UTC']);
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);
        $plan = Plan::create(['code' => 'test', 'name' => 'Test', 'max_monitors' => 20, 'max_websites' => 20, 'min_monitor_interval_seconds' => 60, 'features' => []]);
        Subscription::create([
            'workspace_id' => $this->workspace->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Trialing,
            'billing_cycle' => BillingCycle::Trial,
            'gateway' => PaymentGateway::None,
            'trial_starts_at' => now()->utc(),
            'trial_ends_at' => now()->utc()->addDays(7),
        ]);
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->andReturn(['93.184.216.34']);
            $mock->shouldReceive('ping')->andReturn(['reachable' => true, 'latency_ms' => 5, 'error' => null]);
        });
    }

    private function bootAppPanel(): void
    {
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->workspace);
        Filament::bootCurrentPanel();
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function hosts(): array
    {
        return [
            'scheme, path and case' => ['HTTPS://Daffodilvarsity.edu.bd/admissions?x=1', 'daffodilvarsity.edu.bd'],
            'www is ignored' => ['http://www.daffodilvarsity.edu.bd/', 'daffodilvarsity.edu.bd'],
            'sub-domain kept' => ['https://elearn.daffodilvarsity.edu.bd', 'elearn.daffodilvarsity.edu.bd'],
            'bare host with port' => ['db.example.com:3306', 'db.example.com'],
            'trailing dot' => ['example.com.', 'example.com'],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('hosts')]
    public function test_hosts_are_normalised_for_duplicate_detection(string $value, ?string $expected): void
    {
        $this->assertSame($expected, Monitor::normalizeHost($value));
    }

    public function test_same_domain_cannot_be_monitored_twice_even_with_www_or_another_path(): void
    {
        Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'DIU', 'url' => 'https://daffodilvarsity.edu.bd/']);
        $this->bootAppPanel();

        foreach (['https://www.daffodilvarsity.edu.bd/', 'http://daffodilvarsity.edu.bd/admission'] as $url) {
            Livewire::test(CreateMonitor::class)
                ->fillForm(['type' => 'http', 'name' => 'Duplicate', 'url' => $url, 'timeout_seconds' => 30])
                ->call('create')
                ->assertHasFormErrors(['url']);
        }

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'ping', 'name' => 'Duplicate ping', 'host' => 'daffodilvarsity.edu.bd', 'timeout_seconds' => 30])
            ->call('create')
            ->assertHasFormErrors(['host']);

        $this->assertSame(1, Monitor::count());
    }

    public function test_a_sub_domain_is_a_separate_target_but_also_only_once(): void
    {
        Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://daffodilvarsity.edu.bd/']);
        $this->bootAppPanel();

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'http', 'name' => 'eLearn', 'url' => 'https://elearn.daffodilvarsity.edu.bd/', 'timeout_seconds' => 30])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'http', 'name' => 'eLearn again', 'url' => 'https://elearn.daffodilvarsity.edu.bd/login', 'timeout_seconds' => 30])
            ->call('create')
            ->assertHasFormErrors(['url']);

        $this->assertSame(2, Monitor::count());
    }

    public function test_another_workspace_may_monitor_the_same_domain(): void
    {
        Monitor::factory()->create(['url' => 'https://daffodilvarsity.edu.bd/']);
        $this->bootAppPanel();

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'http', 'name' => 'DIU', 'url' => 'https://daffodilvarsity.edu.bd/', 'timeout_seconds' => 30])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_editing_a_monitor_does_not_flag_itself_and_extra_checks_share_the_host(): void
    {
        $monitor = Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'DIU', 'url' => 'https://daffodilvarsity.edu.bd/']);
        $monitor->children()->create(['workspace_id' => $this->workspace->id, 'type' => MonitorType::Ping, 'name' => 'Ping', 'host' => 'daffodilvarsity.edu.bd']);
        $this->bootAppPanel();

        Livewire::test(EditMonitor::class, ['record' => $monitor->id])
            ->fillForm(['name' => 'DIU main site'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('daffodilvarsity.edu.bd', $monitor->refresh()->target_host);
        $this->assertNull($monitor->children()->sole()->target_host);
    }

    public function test_database_refuses_a_duplicate_main_monitor_even_without_the_form(): void
    {
        Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://daffodilvarsity.edu.bd/']);

        $this->expectException(UniqueConstraintViolationException::class);

        Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://www.daffodilvarsity.edu.bd/']);
    }

    public function test_website_for_an_already_monitored_domain_links_the_existing_monitor(): void
    {
        $monitor = Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://daffodilvarsity.edu.bd/']);

        $website = Website::create(['workspace_id' => $this->workspace->id, 'name' => 'DIU', 'url' => 'https://www.daffodilvarsity.edu.bd']);

        $this->assertSame(1, Monitor::count());
        $this->assertSame($website->id, $monitor->refresh()->website_id);
    }

    public function test_import_links_existing_monitors_instead_of_duplicating(): void
    {
        $monitor = Monitor::factory()->create(['workspace_id' => $this->workspace->id, 'url' => 'https://daffodilvarsity.edu.bd/']);
        Website::create(['workspace_id' => $this->workspace->id, 'name' => 'DIU', 'url' => 'https://daffodilvarsity.edu.bd', 'uptime_monitoring_enabled' => false]);
        $this->bootAppPanel();

        Livewire::test(ListMonitors::class)->call('importWebsites');

        $this->assertSame(1, Monitor::count());
        $this->assertNotNull($monitor->refresh()->website_id);
    }

    public function test_same_website_domain_cannot_be_added_twice_to_a_workspace(): void
    {
        Website::create(['workspace_id' => $this->workspace->id, 'name' => 'DIU', 'url' => 'https://daffodilvarsity.edu.bd', 'uptime_monitoring_enabled' => false]);
        $this->bootAppPanel();

        Livewire::test(CreateWebsite::class)
            ->fillForm(['name' => 'DIU again', 'url' => 'https://www.daffodilvarsity.edu.bd/', 'environment' => 'production', 'status' => 'active'])
            ->call('create')
            ->assertHasFormErrors(['url']);

        $this->assertSame(1, Website::count());
    }
}
