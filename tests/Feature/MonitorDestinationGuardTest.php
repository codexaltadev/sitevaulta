<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Filament\App\Resources\Monitors\Pages\CreateMonitor;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Monitoring\DestinationGuard;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\NetworkProbe;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitorDestinationGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, bool}>
     */
    public static function addresses(): array
    {
        return [
            'loopback v4' => ['127.0.0.1', false],
            'private 10/8' => ['10.0.0.5', false],
            'private 172.16/12' => ['172.20.1.1', false],
            'private 192.168/16' => ['192.168.1.10', false],
            'cloud metadata' => ['169.254.169.254', false],
            'CGNAT' => ['100.64.0.1', false],
            'unspecified' => ['0.0.0.0', false],
            'loopback v6' => ['::1', false],
            'unique local v6' => ['fd00::1', false],
            'v4-mapped loopback' => ['::ffff:127.0.0.1', false],
            'public v4' => ['13.235.168.200', true],
            'public v6' => ['2606:4700:4700::1111', true],
        ];
    }

    #[DataProvider('addresses')]
    public function test_only_globally_routable_addresses_are_public(string $ip, bool $isPublic): void
    {
        $this->assertSame($isPublic, DestinationGuard::isPublicIp($ip));
    }

    public function test_local_names_and_hosts_resolving_to_private_ips_are_denied(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->with('evil.example')->andReturn(['93.184.216.34', '127.0.0.1']);
            $mock->shouldReceive('resolveIps')->with('good.example')->andReturn(['93.184.216.34']);
        });
        $guard = app(DestinationGuard::class);

        $this->assertFalse($guard->inspectHost('localhost')['allowed']);
        $this->assertFalse($guard->inspectHost('redis.internal')['allowed']);
        $this->assertSame(
            'evil.example resolves to a private or reserved address (127.0.0.1); only public addresses can be monitored',
            $guard->inspectHost('evil.example')['reason'],
        );
        $this->assertTrue($guard->inspectHost('good.example')['allowed']);
        $this->assertFalse($guard->inspectUrl('file:///etc/passwd')['allowed']);
        $this->assertFalse($guard->inspectUrl('gopher://good.example/')['allowed']);
    }

    public function test_http_check_to_a_private_address_is_blocked_before_any_request(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->with('rebind.example')->andReturn(['10.0.0.8']);
        });
        $monitor = Monitor::factory()->create(['url' => 'http://rebind.example/admin']);

        app(MonitorCheckRunner::class)->run($monitor);

        Http::assertNothingSent();
        $this->assertSame(MonitorStatus::Down, $monitor->refresh()->status);
        $incident = MonitorIncident::sole();
        $this->assertSame('blocked', $incident->root_cause_type);
        $this->assertStringContainsString('10.0.0.8', $incident->root_cause);
    }

    public function test_redirect_into_the_private_network_is_not_followed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://public.example/' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->with('public.example')->andReturn(['93.184.216.34']);
            $mock->shouldReceive('resolveIps')->with('169.254.169.254')->andReturn(['169.254.169.254']);
        });
        $monitor = Monitor::factory()->create(['url' => 'https://public.example/']);

        app(MonitorCheckRunner::class)->run($monitor);

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '169.254.169.254'));
        $this->assertSame('blocked', MonitorIncident::sole()->root_cause_type);
    }

    public function test_redirects_between_public_addresses_are_followed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'http://shop.example/' => Http::response('', 301, ['Location' => 'https://shop.example/home']),
            'https://shop.example/home' => Http::response('Welcome', 200),
        ]);
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->andReturn(['93.184.216.34']);
        });
        $monitor = Monitor::factory()->create(['url' => 'http://shop.example/']);

        $check = app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame(MonitorStatus::Up, $check->status);
        $this->assertSame(200, $check->http_status_code);
    }

    public function test_port_and_ping_checks_never_touch_localhost(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->andReturn(['127.0.0.1']);
            $mock->shouldNotReceive('tcpConnect');
            $mock->shouldNotReceive('ping');
        });
        $port = Monitor::factory()->create(['type' => MonitorType::Port, 'url' => null, 'host' => '127.0.0.1', 'port' => 6379]);
        $ping = Monitor::factory()->create(['type' => MonitorType::Ping, 'url' => null, 'host' => '127.0.0.1']);

        app(MonitorCheckRunner::class)->run($port);
        app(MonitorCheckRunner::class)->run($ping);

        $this->assertSame(['blocked', 'blocked'], MonitorIncident::orderBy('id')->pluck('root_cause_type')->all());
    }

    public function test_form_rejects_private_targets(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $user->id, 'billing_currency' => 'BDT', 'timezone' => 'UTC']);
        $workspace->members()->attach($user->id, ['role' => 'owner']);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($workspace);
        Filament::bootCurrentPanel();

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'http', 'name' => 'Metadata', 'url' => 'http://169.254.169.254/latest/', 'timeout_seconds' => 30])
            ->call('create')
            ->assertHasFormErrors(['url']);

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'port', 'name' => 'Redis', 'host' => 'localhost', 'port' => 6379, 'timeout_seconds' => 30])
            ->call('create')
            ->assertHasFormErrors(['host']);

        $this->assertSame(0, Monitor::count());
    }
}
