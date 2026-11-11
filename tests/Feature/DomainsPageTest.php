<?php

namespace Tests\Feature;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\User;
use App\Models\VerifiedDomain;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Monitoring\DomainOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainsPageTest extends TestCase
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
    }

    /**
     * shop.example: verified backup website + its monitor (+ SSL check); api.example: monitor only, down;
     * docs.example: a domain verified for port checks only; draft.example: unverified backup website.
     */
    private function seedDomains(): void
    {
        $shop = $this->verifyWebsiteOwnership(Website::create(['workspace_id' => $this->workspace->id, 'name' => 'Shop', 'url' => 'https://www.shop.example', 'uptime_monitoring_enabled' => false]));
        $shopMonitor = Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'website_id' => $shop->id, 'name' => 'Shop', 'url' => 'https://shop.example/']);
        Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'parent_id' => $shopMonitor->id, 'type' => MonitorType::Ssl, 'url' => null, 'host' => 'shop.example']);
        Monitor::factory()->down()->create(['workspace_id' => $this->workspace->id, 'name' => 'API', 'url' => 'https://api.example/health', 'last_error' => 'HTTP 502 Bad Gateway']);
        VerifiedDomain::factory()->verified()->create(['workspace_id' => $this->workspace->id, 'domain' => 'docs.example']);
        Website::create(['workspace_id' => $this->workspace->id, 'name' => 'Draft', 'url' => 'https://draft.example', 'uptime_monitoring_enabled' => false]);
    }

    public function test_each_domain_is_one_row_joining_ownership_backups_and_uptime(): void
    {
        $this->seedDomains();

        $rows = collect(app(DomainOverview::class)->rows($this->workspace))->keyBy('host');

        $this->assertEqualsCanonicalizing(['shop.example', 'api.example', 'docs.example', 'draft.example'], $rows->keys()->all());
        $this->assertSame('Shop', $rows['shop.example']['website']->name, 'www. is the same domain.');
        $this->assertSame('Shop', $rows['shop.example']['monitor']->name);
        $this->assertTrue($rows['shop.example']['ownership']->isVerified());
        $this->assertSame([], $rows['shop.example']['issues']);
        $this->assertSame(['Down: HTTP 502 Bad Gateway'], $rows['api.example']['issues']);
        $this->assertSame(['Verify ownership to start backups'], $rows['draft.example']['issues']);
        $this->assertNull($rows['docs.example']['website']);
    }

    public function test_domains_needing_attention_come_first_and_are_counted(): void
    {
        $this->seedDomains();
        VerifiedDomain::where('domain', 'shop.example')->update(['token_missing_since' => now()->subDays(2)]);

        $overview = app(DomainOverview::class);
        $rows = $overview->rows($this->workspace);

        $this->assertSame(['api.example', 'draft.example', 'shop.example', 'docs.example'], array_column($rows, 'host'));
        $this->assertSame(['Verification token missing — 5 days left'], $rows[2]['issues']);
        $this->assertSame(['domains' => 4, 'verified' => 2, 'attention' => 3, 'down' => 1, 'backed_up' => 2], $overview->summary($rows));
    }

    public function test_page_lists_the_workspace_domains_with_search_and_attention_filter(): void
    {
        $this->seedDomains();
        $other = Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => User::factory()->create()->id, 'billing_currency' => 'BDT', 'timezone' => 'UTC']);
        VerifiedDomain::factory()->verified()->create(['workspace_id' => $other->id, 'domain' => 'secret.example']);

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/domains")
            ->assertOk()
            ->assertSeeInOrder(['api.example', 'Down: HTTP 502 Bad Gateway', 'draft.example', 'docs.example', 'shop.example'])
            ->assertSee('✓ Verified')
            ->assertSee('Not monitored')
            ->assertDontSee('secret.example');

        $this->get("/app/{$this->workspace->slug}/domains?show=attention")
            ->assertOk()
            ->assertSee('api.example')
            ->assertDontSee('docs.example');

        $this->get("/app/{$this->workspace->slug}/domains?search=shop")
            ->assertOk()
            ->assertSee('shop.example')
            ->assertDontSee('api.example');
    }
}
