<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\MonitorType;
use App\Enums\PaymentGateway;
use App\Enums\SubscriptionStatus;
use App\Enums\VerificationMethod;
use App\Enums\WebsiteVerificationStatus;
use App\Filament\App\Resources\Monitors\Pages\CreateMonitor;
use App\Filament\App\Resources\VerifiedDomains\Pages\ListVerifiedDomains;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\Plan;
use App\Models\ServerConnection;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VerifiedDomain;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Monitoring\HostOwnershipVerifier;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\NetworkProbe;
use App\Services\Verification\DomainVerificationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class HostOwnershipTest extends TestCase
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

        $plan = Plan::create(['code' => 'test', 'name' => 'Test', 'max_monitors' => 10, 'min_monitor_interval_seconds' => 60, 'features' => []]);
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

    private function fakeDns(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->andReturnUsing(fn (string $host): array => match ($host) {
                'acme.example', 'db.acme.example' => ['93.184.216.34'],
                '93.184.216.34' => ['93.184.216.34'],
                'server.acme-hosting.example' => ['93.184.216.99'],
                '93.184.216.99' => ['93.184.216.99'],
                default => ['198.51.100.200'],
            });
            $mock->shouldReceive('dnsRecords')->andReturn([]);
        });
    }

    private function bootAppPanel(): void
    {
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->workspace);
        Filament::bootCurrentPanel();
    }

    public function test_verified_website_owns_only_its_exact_host_and_ip(): void
    {
        $this->fakeDns();
        Website::create(['workspace_id' => $this->workspace->id, 'name' => 'Acme', 'url' => 'https://www.acme.example', 'verification_status' => WebsiteVerificationStatus::Verified, 'uptime_monitoring_enabled' => false]);
        $verifier = app(HostOwnershipVerifier::class);

        $this->assertTrue($verifier->ownership($this->workspace, 'acme.example')['owned']);
        $this->assertTrue($verifier->ownership($this->workspace, 'www.acme.example')['owned']);
        $this->assertFalse($verifier->ownership($this->workspace, 'shop.acme.example')['owned'], 'A sub-domain hosted elsewhere needs its own proof.');
        $this->assertSame('an IP address of one of your verified domains or servers', $verifier->ownership($this->workspace, 'db.acme.example')['via']);
        $this->assertTrue($verifier->ownership($this->workspace, '93.184.216.34')['owned']);
        $this->assertFalse($verifier->ownership($this->workspace, 'bank.example')['owned']);
    }

    public function test_unverified_website_or_pending_domain_proves_nothing(): void
    {
        $this->fakeDns();
        Website::create(['workspace_id' => $this->workspace->id, 'name' => 'Acme', 'url' => 'https://acme.example', 'uptime_monitoring_enabled' => false]);
        VerifiedDomain::create(['workspace_id' => $this->workspace->id, 'domain' => 'acme.example']);

        $this->assertFalse(app(HostOwnershipVerifier::class)->ownership($this->workspace, 'acme.example')['owned']);
    }

    public function test_only_a_server_that_authenticated_counts_as_proof(): void
    {
        $this->fakeDns();
        $website = Website::create(['workspace_id' => $this->workspace->id, 'name' => 'Acme', 'url' => 'https://other.example', 'uptime_monitoring_enabled' => false]);
        $connection = ServerConnection::create([
            'workspace_id' => $this->workspace->id,
            'website_id' => $website->id,
            'name' => 'VPS',
            'file_protocol' => 'ssh_sftp',
            'file_credentials' => ['host' => 'server.acme-hosting.example', 'port' => 22, 'username' => 'deploy'],
        ]);
        $verifier = app(HostOwnershipVerifier::class);

        $this->assertFalse($verifier->ownership($this->workspace, 'server.acme-hosting.example')['owned']);

        $connection->forceFill(['capabilities' => ['files' => true]])->save();

        $this->assertTrue($verifier->ownership($this->workspace, 'server.acme-hosting.example')['owned']);
        $this->assertTrue($verifier->ownership($this->workspace, '93.184.216.99')['owned']);
    }

    public function test_another_workspaces_verified_domain_does_not_count(): void
    {
        $this->fakeDns();
        $other = Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => User::factory()->create()->id, 'billing_currency' => 'BDT', 'timezone' => 'UTC']);
        VerifiedDomain::factory()->verified()->create(['workspace_id' => $other->id, 'domain' => 'acme.example']);

        $this->assertFalse(app(HostOwnershipVerifier::class)->ownership($this->workspace, 'acme.example')['owned']);
    }

    public function test_dns_txt_token_verifies_a_domain(): void
    {
        $domain = VerifiedDomain::create(['workspace_id' => $this->workspace->id, 'domain' => 'https://www.Acme.example/']);
        $this->mock(NetworkProbe::class, function (MockInterface $mock) use ($domain): void {
            $mock->shouldReceive('dnsRecords')->with('_sitevaulta-challenge.acme.example', 'TXT')->andReturn([$domain->token]);
        });

        $this->assertSame('acme.example', $domain->domain);
        $this->assertTrue(app(HostOwnershipVerifier::class)->verify($domain));
        $this->assertSame('dns_txt', $domain->refresh()->verification_method);
        $this->assertNotNull($domain->verified_at);
    }

    public function test_homepage_meta_tag_verifies_a_domain_like_a_backup_website(): void
    {
        $this->fakeDns();
        $domain = VerifiedDomain::create(['workspace_id' => $this->workspace->id, 'domain' => 'acme.example']);
        Http::fake([
            'https://acme.example/.well-known/*' => Http::response('Not found', 404),
            'https://acme.example/' => Http::response('<html><head>'.$domain->metaTag().'</head></html>'),
        ]);

        $this->assertTrue(app(HostOwnershipVerifier::class)->verify($domain));
        $this->assertSame('meta_tag', $domain->refresh()->verification_method);
        $this->assertSame('HTML meta tag', $domain->methodLabel());
    }

    public function test_verification_file_in_the_web_root_works_without_a_well_known_folder(): void
    {
        $this->fakeDns();
        $domain = VerifiedDomain::create(['workspace_id' => $this->workspace->id, 'domain' => 'acme.example']);
        Http::fake([
            'https://acme.example/.well-known/*' => Http::response('Not found', 404),
            'https://acme.example/sitevaulta-verify.txt' => Http::response($domain->token),
            '*' => Http::response('', 404),
        ]);

        $this->assertTrue(app(HostOwnershipVerifier::class)->verify($domain));
        $this->assertSame('html_file', $domain->refresh()->verification_method);
    }

    public function test_weekly_reverification_warns_once_then_revokes_and_notifies(): void
    {
        $this->fakeDns();
        Http::fake(['*' => Http::response('', 404)]);
        $domain = VerifiedDomain::factory()->verified()->create(['workspace_id' => $this->workspace->id, 'domain' => 'acme.example', 'last_attempted_at' => now()->subDays(8)]);

        $this->artisan('sitevaulta:reverify-domains')->expectsOutput('Re-verified 0 | warnings 1 | revoked 0');
        $this->assertTrue($domain->refresh()->isVerified(), 'One miss is only a warning.');
        $this->assertSame(1, $domain->reverify_failures);

        $this->artisan('sitevaulta:reverify-domains')->expectsOutput('Re-verified 0 | warnings 0 | revoked 0');

        $this->travel(8)->days();
        $this->artisan('sitevaulta:reverify-domains')->expectsOutput('Re-verified 0 | warnings 0 | revoked 1');

        $this->assertFalse($domain->refresh()->isVerified());
        $this->assertFalse(app(HostOwnershipVerifier::class)->ownership($this->workspace, 'acme.example')['owned']);
        $this->assertEqualsCanonicalizing(
            ['Could not re-verify acme.example', 'Ownership of acme.example is no longer verified'],
            $this->user->notifications()->get()->pluck('data.title')->all(),
        );
    }

    public function test_reverification_that_finds_the_token_resets_the_failure_count(): void
    {
        $domain = VerifiedDomain::factory()->verified()->create(['workspace_id' => $this->workspace->id, 'domain' => 'acme.example', 'last_attempted_at' => now()->subDays(8), 'reverify_failures' => 1]);
        $this->mock(NetworkProbe::class, function (MockInterface $mock) use ($domain): void {
            $mock->shouldReceive('dnsRecords')->andReturn([$domain->token]);
        });

        $this->artisan('sitevaulta:reverify-domains')->expectsOutput('Re-verified 1 | warnings 0 | revoked 0');

        $this->assertSame(0, $domain->refresh()->reverify_failures);
        $this->assertTrue($domain->isVerified());
    }

    public function test_backup_website_domains_are_not_reverified_here(): void
    {
        Website::create(['workspace_id' => $this->workspace->id, 'name' => 'Acme', 'url' => 'https://acme.example', 'verification_status' => WebsiteVerificationStatus::Verified, 'uptime_monitoring_enabled' => false]);
        VerifiedDomain::query()->update(['last_attempted_at' => now()->subDays(30)]);

        $this->artisan('sitevaulta:reverify-domains')->expectsOutput('Re-verified 0 | warnings 0 | revoked 0');
    }

    public function test_failed_verification_explains_all_three_options(): void
    {
        $this->fakeDns();
        $domain = VerifiedDomain::create(['workspace_id' => $this->workspace->id, 'domain' => 'acme.example']);
        Http::fake([
            'https://acme.example/' => Http::response('<html><head></head></html>'),
            '*' => Http::response('Not found', 404),
        ]);

        $this->assertFalse(app(HostOwnershipVerifier::class)->verify($domain));
        $this->assertSame([
            'DNS TXT record: not found yet. New records can take a few minutes to appear.',
            'Verification file: not uploaded yet (404 Not Found).',
            'Meta tag: not found in the homepage <head>.',
        ], $domain->errorLines());
    }

    public function test_verified_domains_table_shows_a_short_status_and_the_details_as_a_list(): void
    {
        VerifiedDomain::factory()->create([
            'workspace_id' => $this->workspace->id,
            'domain' => 'acme.example',
            'last_attempted_at' => now(),
            'last_error' => "DNS TXT record: not found yet.\nVerification file: not uploaded yet (404 Not Found).",
        ]);

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/verified-domains")
            ->assertOk()
            ->assertSee('Token not found yet — hover or open &quot;How to verify&quot;', false)
            ->assertDontSee('DNS TXT record: not found yet. Verification file');
    }

    public function test_backup_website_verification_uses_the_same_checks(): void
    {
        $this->fakeDns();
        $website = Website::create(['workspace_id' => $this->workspace->id, 'name' => 'Acme', 'url' => 'https://acme.example', 'uptime_monitoring_enabled' => false]);
        $challenge = DomainVerificationService::ensureChallengeExists($website);
        Http::fake(['https://acme.example/' => Http::response('<meta name="sitevaulta-verification" content="'.$challenge->token.'">')]);

        $result = DomainVerificationService::verifyWebsite($website, VerificationMethod::MetaTag);

        $this->assertTrue($result->verified, $result->message);
        $this->assertTrue($website->refresh()->isVerified());
        $this->assertSame(VerifiedDomain::METHOD_BACKUP_WEBSITE, VerifiedDomain::where('domain', 'acme.example')->sole()->verification_method);
    }

    public function test_port_type_is_locked_until_the_workspace_owns_a_host_and_a_forged_choice_is_still_rejected(): void
    {
        $this->fakeDns();
        $this->bootAppPanel();

        Livewire::test(CreateMonitor::class)
            ->assertSee('🔒 Port')
            ->assertSee('Port checks unlock after you verify a domain you own.')
            ->fillForm(['type' => 'port', 'name' => 'Bank SSH', 'host' => 'bank.example', 'ports' => ['22'], 'timeout_seconds' => 30])
            ->call('create')
            ->assertHasFormErrors(['host']);

        $this->assertSame(0, Monitor::count());
    }

    public function test_ports_stay_disabled_until_the_host_is_verified(): void
    {
        $this->fakeDns();
        VerifiedDomain::factory()->verified()->create(['workspace_id' => $this->workspace->id, 'domain' => 'acme.example']);
        $this->bootAppPanel();

        Livewire::test(CreateMonitor::class)
            ->assertDontSee('🔒 Port')
            ->fillForm(['type' => 'port', 'host' => 'bank.example'])
            ->assertFormFieldDisabled('ports')
            ->assertSee('✗ Ownership not verified')
            ->fillForm(['host' => 'acme.example'])
            ->assertFormFieldEnabled('ports')
            ->assertSee('✓ Verified owner');
    }

    public function test_server_ports_extra_check_is_locked_for_a_host_that_is_not_owned(): void
    {
        $this->fakeDns();
        VerifiedDomain::factory()->verified()->create(['workspace_id' => $this->workspace->id, 'domain' => 'acme.example']);
        $this->bootAppPanel();

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'http', 'name' => 'Bank', 'url' => 'https://bank.example/'])
            ->assertSee('Locked: bank.example is not verified as yours.')
            ->fillForm(['extra_checks' => ['port'], 'extras' => ['port' => ['ports' => ['22']]]])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(0, Monitor::count(), 'A forged tick is rejected by the server.');

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'http', 'name' => 'Acme', 'url' => 'https://acme.example/'])
            ->assertDontSee('Locked: acme.example');
    }

    public function test_port_monitor_needs_ownership_in_the_form_and_on_every_run(): void
    {
        $this->fakeDns();
        $this->bootAppPanel();

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'port', 'name' => 'Bank SSH', 'host' => 'bank.example', 'ports' => ['22'], 'timeout_seconds' => 30])
            ->call('create')
            ->assertHasFormErrors(['host']);

        $verified = VerifiedDomain::factory()->verified()->create(['workspace_id' => $this->workspace->id, 'domain' => 'acme.example']);

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'port', 'name' => 'Acme DB', 'host' => 'db.acme.example', 'ports' => ['3306'], 'timeout_seconds' => 30])
            ->call('create')
            ->assertHasNoFormErrors();

        $verified->delete();
        $monitor = Monitor::where('type', MonitorType::Port)->sole();
        app(MonitorCheckRunner::class)->run($monitor->load('workspace'));

        $this->assertStringContainsString('ownership of db.acme.example is not verified', MonitorIncident::sole()->root_cause);
    }

    public function test_multi_port_monitor_names_every_closed_port(): void
    {
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->andReturn(['93.184.216.34']);
            $mock->shouldReceive('tcpConnect')->with('93.184.216.34', 22, 10)->andReturn(['connected' => true, 'latency_ms' => 20]);
            $mock->shouldReceive('tcpConnect')->with('93.184.216.34', 3306, 10)->andReturn(['connected' => false, 'latency_ms' => null]);
            $mock->shouldReceive('tcpConnect')->with('93.184.216.34', 6379, 10)->andReturn(['connected' => true, 'latency_ms' => 40]);
        });
        VerifiedDomain::factory()->verified()->create(['workspace_id' => $this->workspace->id, 'domain' => 'acme.example']);
        $monitor = Monitor::factory()->create([
            'workspace_id' => $this->workspace->id,
            'type' => MonitorType::Port,
            'url' => null,
            'host' => 'db.acme.example',
            'ports' => [22, 3306, 6379],
        ]);

        app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame(22, $monitor->refresh()->port);
        $this->assertSame('db.acme.example : 22, 3306, 6379', $monitor->targetLabel());
        $incident = MonitorIncident::sole();
        $this->assertSame('Closed: MySQL 3306', $incident->root_cause);
        $this->assertSame("SSH 22: open (20 ms)\nMySQL 3306: closed\nRedis 6379: open (40 ms)", $incident->response_body);
    }

    public function test_port_form_limits_ports_and_interval(): void
    {
        $this->fakeDns();
        VerifiedDomain::factory()->verified()->create(['workspace_id' => $this->workspace->id, 'domain' => 'acme.example']);
        $this->bootAppPanel();

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'port', 'name' => 'Too many', 'host' => 'acme.example', 'ports' => array_map('strval', range(1, 11)), 'timeout_seconds' => 30])
            ->call('create')
            ->assertHasFormErrors(['ports']);

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'port', 'name' => 'Fast scan', 'host' => 'acme.example', 'ports' => ['22'], 'interval_seconds' => 60, 'timeout_seconds' => 30])
            ->call('create')
            ->assertHasFormErrors(['interval_seconds']);

        Livewire::test(CreateMonitor::class)
            ->fillForm(['type' => 'port', 'name' => 'Bad port', 'host' => 'acme.example', 'ports' => ['70000'], 'timeout_seconds' => 30])
            ->call('create')
            ->assertHasFormErrors(['ports.0']);

        $this->assertSame(0, Monitor::count());
    }

    public function test_port_extra_check_needs_ownership_and_is_audited(): void
    {
        $this->fakeDns();
        $this->bootAppPanel();
        $formData = [
            'type' => 'http',
            'name' => 'acme.example',
            'url' => 'https://acme.example/',
            'timeout_seconds' => 30,
            'extra_checks' => ['port'],
            'extras' => ['port' => ['ports' => ['22', '3306']]],
        ];

        Livewire::test(CreateMonitor::class)
            ->fillForm($formData)
            ->call('create')
            ->assertHasFormErrors(['extras.port.ports']);

        VerifiedDomain::factory()->verified()->create(['workspace_id' => $this->workspace->id, 'domain' => 'acme.example']);

        Livewire::test(CreateMonitor::class)
            ->fillForm($formData)
            ->call('create')
            ->assertHasNoFormErrors();

        $child = Monitor::where('type', MonitorType::Port)->sole();
        $this->assertSame([22, 3306], $child->portList());
        $this->assertSame(300, $child->interval_seconds);
        $this->assertDatabaseHas('audit_logs', ['event' => 'monitoring.port_check.created', 'workspace_id' => $this->workspace->id]);
    }

    public function test_verified_domains_page_adds_and_lists_domains(): void
    {
        $this->bootAppPanel();

        Livewire::test(ListVerifiedDomains::class)
            ->callAction('create', ['domain' => 'https://www.acme.example/'])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('verified_domains', ['workspace_id' => $this->workspace->id, 'domain' => 'acme.example', 'verified_at' => null]);

        $this->get("/app/{$this->workspace->slug}/verified-domains")
            ->assertOk()
            ->assertSee('acme.example')
            ->assertSee('Pending');
    }

    public function test_verified_domains_page_lists_websites_already_verified_in_backup_management(): void
    {
        Website::create(['workspace_id' => $this->workspace->id, 'name' => 'Acme', 'url' => 'https://acme.example', 'verification_status' => WebsiteVerificationStatus::Verified, 'uptime_monitoring_enabled' => false]);
        Website::create(['workspace_id' => $this->workspace->id, 'name' => 'Draft', 'url' => 'https://draft.example', 'uptime_monitoring_enabled' => false]);

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/verified-domains")
            ->assertOk()
            ->assertSeeInOrder(['acme.example', 'Verified', 'via backup website verification', 'Backup website', 'Acme'])
            ->assertDontSee('draft.example');
    }

    public function test_website_verification_is_mirrored_into_verified_domains(): void
    {
        $website = Website::create(['workspace_id' => $this->workspace->id, 'name' => 'Acme', 'url' => 'https://www.acme.example', 'verification_status' => WebsiteVerificationStatus::Pending, 'uptime_monitoring_enabled' => false]);
        $this->assertSame(0, VerifiedDomain::count());

        $website->update(['verification_status' => WebsiteVerificationStatus::Verified]);
        $mirrored = VerifiedDomain::sole();
        $this->assertSame(['acme.example', $website->id, VerifiedDomain::METHOD_BACKUP_WEBSITE], [$mirrored->domain, $mirrored->website_id, $mirrored->verification_method]);
        $this->assertTrue($mirrored->isVerified());

        $website->update(['url' => 'https://shop.acme.example']);
        $this->assertSame(['shop.acme.example'], VerifiedDomain::pluck('domain')->all());

        $website->update(['verification_status' => WebsiteVerificationStatus::Failed]);
        $this->assertSame(0, VerifiedDomain::count());
    }

    public function test_a_domain_proven_by_dns_keeps_its_proof_when_the_website_links_and_unlinks(): void
    {
        $proven = VerifiedDomain::factory()->verified()->create(['workspace_id' => $this->workspace->id, 'domain' => 'acme.example']);
        $pending = VerifiedDomain::factory()->create(['workspace_id' => $this->workspace->id, 'domain' => 'shop.example']);

        $website = Website::create(['workspace_id' => $this->workspace->id, 'name' => 'Acme', 'url' => 'https://acme.example', 'verification_status' => WebsiteVerificationStatus::Verified, 'uptime_monitoring_enabled' => false]);
        Website::create(['workspace_id' => $this->workspace->id, 'name' => 'Shop', 'url' => 'https://shop.example', 'verification_status' => WebsiteVerificationStatus::Verified, 'uptime_monitoring_enabled' => false]);

        $this->assertSame(['dns_txt', $website->id], [$proven->fresh()->verification_method, $proven->fresh()->website_id]);
        $this->assertTrue($pending->fresh()->isFromBackupWebsite(), 'A pending manual row is completed by the website verification.');

        $website->update(['verification_status' => WebsiteVerificationStatus::Unverified]);
        $this->assertTrue($proven->fresh()->isVerified());
        $this->assertNull($proven->fresh()->website_id);
    }
}
