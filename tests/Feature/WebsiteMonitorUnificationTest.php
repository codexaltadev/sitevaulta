<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\MonitorStatus;
use App\Enums\PaymentGateway;
use App\Enums\SubscriptionStatus;
use App\Enums\WebsiteVerificationStatus;
use App\Models\Monitor;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Verification\WebsiteUptimeMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebsiteMonitorUnificationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $this->workspace = Workspace::create([
            'name' => 'Milton Agency',
            'slug' => 'milton-agency',
            'owner_id' => $owner->id,
            'billing_currency' => 'BDT',
            'timezone' => 'UTC',
        ]);
        $this->subscribeToPlan(maxMonitors: 10);
    }

    private function subscribeToPlan(int $maxMonitors): void
    {
        $plan = Plan::firstOrCreate(['code' => "test-{$maxMonitors}"], [
            'name' => 'Test Plan',
            'max_monitors' => $maxMonitors,
            'min_monitor_interval_seconds' => 300,
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
        $this->workspace->unsetRelation('currentSubscription');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createWebsite(array $attributes = []): Website
    {
        return Website::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Shop',
            'url' => 'https://shop.test',
            'verification_status' => WebsiteVerificationStatus::Verified,
            'status' => 'active',
            ...$attributes,
        ]);
    }

    public function test_new_website_gets_a_linked_http_monitor(): void
    {
        $website = $this->createWebsite();

        $monitor = $website->monitors()->sole();
        $this->assertSame($this->workspace->id, $monitor->workspace_id);
        $this->assertSame('Shop', $monitor->name);
        $this->assertSame('https://shop.test', $monitor->url);
        $this->assertSame(300, $monitor->interval_seconds);
    }

    public function test_verifying_ownership_creates_the_monitor_that_was_skipped_earlier(): void
    {
        $this->subscribeToPlan(maxMonitors: 0);
        $website = $this->createWebsite(['url' => 'https://pending.test', 'verification_status' => WebsiteVerificationStatus::Pending]);
        $this->assertSame(0, $website->monitors()->count());

        $this->subscribeToPlan(maxMonitors: 10);
        $website->fresh()->update(['verification_status' => WebsiteVerificationStatus::Verified]);

        $monitor = $website->monitors()->sole();
        $this->assertSame('https://pending.test', $monitor->url);
    }

    public function test_failed_verification_does_not_create_a_monitor(): void
    {
        $this->subscribeToPlan(maxMonitors: 0);
        $website = $this->createWebsite(['url' => 'https://pending.test', 'verification_status' => WebsiteVerificationStatus::Pending]);

        $this->subscribeToPlan(maxMonitors: 10);
        $website->fresh()->update(['verification_status' => WebsiteVerificationStatus::Failed]);

        $this->assertSame(0, $website->monitors()->count());
    }

    public function test_no_monitor_is_created_when_quota_is_full_or_monitoring_is_off(): void
    {
        $this->subscribeToPlan(maxMonitors: 0);
        $overQuota = $this->createWebsite(['name' => 'Over quota', 'url' => 'https://over.test']);

        $this->subscribeToPlan(maxMonitors: 10);
        $disabled = $this->createWebsite(['name' => 'Disabled', 'url' => 'https://disabled.test', 'uptime_monitoring_enabled' => false]);

        $this->assertSame(0, $overQuota->monitors()->count());
        $this->assertSame(0, $disabled->monitors()->count());
    }

    public function test_website_changes_are_mirrored_onto_its_monitor(): void
    {
        $website = $this->createWebsite();

        $website->update(['uptime_monitoring_enabled' => false]);
        $this->assertSame(MonitorStatus::Paused, $website->monitors()->sole()->status);

        $website->update(['uptime_monitoring_enabled' => true, 'url' => 'https://new-shop.test']);
        $monitor = $website->monitors()->sole();
        $this->assertSame(MonitorStatus::Pending, $monitor->status);
        $this->assertSame('https://new-shop.test', $monitor->url);
    }

    public function test_website_live_check_runs_through_linked_monitor_and_fills_website_columns(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://shop.test' => Http::response('Bad Gateway', 502)]);
        $website = $this->createWebsite();

        $result = WebsiteUptimeMonitorService::checkWebsiteLiveStatus($website);

        $this->assertFalse($result['is_online']);
        $this->assertSame(502, $result['http_status']);
        $monitor = $website->monitors()->sole();
        $this->assertSame(MonitorStatus::Down, $monitor->status);
        $this->assertSame(1, $monitor->checks()->count());
        $this->assertSame(1, $monitor->incidents()->count());

        $website->refresh();
        $this->assertSame('down', $website->uptime_status);
        $this->assertSame(502, $website->last_http_status_code);
        $this->assertSame('HTTP 502 Bad Gateway', $website->last_down_reason);
    }

    public function test_recovery_on_the_monitor_marks_website_online(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://shop.test' => Http::response('ok', 200)]);
        $website = $this->createWebsite(['uptime_status' => 'down']);

        WebsiteUptimeMonitorService::checkWebsiteLiveStatus($website);

        $website->refresh();
        $this->assertSame('online', $website->uptime_status);
        $this->assertSame(200, $website->last_http_status_code);
        $this->assertNull($website->last_down_reason);
    }

    public function test_scheduled_website_command_leaves_http_checks_to_monitors(): void
    {
        Http::preventStrayRequests();
        $monitored = $this->createWebsite();
        $this->subscribeToPlan(maxMonitors: 1);
        $this->createWebsite(['name' => 'Unmonitored', 'url' => 'https://unmonitored.test', 'domain' => 'unmonitored.test']);

        $this->artisan('sitevaulta:check-websites-uptime')
            ->expectsOutput('Checked 2 verified websites | Server & SSL only (monitor-linked): 1 | Legacy full check: 1')
            ->assertSuccessful();

        $this->assertSame(0, $monitored->monitors()->sole()->checks()->count());
    }

    public function test_sync_command_backfills_monitors_for_existing_websites(): void
    {
        $website = $this->createWebsite();
        $website->monitors()->delete();

        $this->artisan('sitevaulta:sync-website-monitors')
            ->expectsOutput('Created 1 website monitors | Skipped (quota full or no active plan): 0')
            ->assertSuccessful();

        $this->assertInstanceOf(Monitor::class, $website->monitors()->first());
    }
}
