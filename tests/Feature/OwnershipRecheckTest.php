<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VerifiedDomain;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Monitoring\HostOwnershipVerifier;
use App\Services\Monitoring\NetworkProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class OwnershipRecheckTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    /**
     * What the customer's site looks like right now: 'token_gone', 'site_down', 'dns_down' or 'token_back'.
     */
    private string $siteState = 'token_back';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(4, 0));
        $this->user = User::factory()->create();
        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $this->user->id, 'billing_currency' => 'BDT', 'timezone' => 'UTC']);
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);

        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->andReturn(['93.184.216.34']);
            $mock->shouldReceive('dnsRecords')->andReturnUsing(fn (): ?array => match ($this->siteState) {
                'dns_down', 'site_down' => null,
                'token_back' => [VerifiedDomain::sole()->token],
                default => [],
            });
        });

        Http::fake(fn (Request $request) => match (true) {
            $this->siteState === 'site_down' => Http::response('Bad gateway', 502),
            str_ends_with($request->url(), '.txt') => Http::response('Not found', 404),
            default => Http::response('<html><head><title>Shop</title></head></html>'),
        });
    }

    private function verifiedWebsite(): Website
    {
        return $this->verifyWebsiteOwnership(Website::create(['workspace_id' => $this->workspace->id, 'name' => 'Shop', 'url' => 'https://shop.example', 'uptime_monitoring_enabled' => false]));
    }

    /**
     * @return list<string>
     */
    private function notificationTitles(): array
    {
        return $this->user->notifications()->get()->sortBy('created_at')->pluck('data.title')->values()->all();
    }

    public function test_a_removed_token_gets_a_notice_reminders_and_pauses_backups_after_seven_days(): void
    {
        $website = $this->verifiedWebsite();
        $this->siteState = 'token_gone';

        $this->travel(1)->days();
        $this->artisan('sitevaulta:reverify-domains')->expectsOutputToContain('notice 1');
        $row = VerifiedDomain::sole();
        $this->assertTrue($row->isInGracePeriod());
        $this->assertTrue($website->fresh()->isVerified(), 'Backups keep running during the grace period.');
        $this->assertSame(7, $row->graceDaysLeft());

        foreach ([1, 2] as $day) {
            $this->travel(1)->days();
            $this->artisan('sitevaulta:reverify-domains')->expectsOutputToContain('waiting 1');
        }

        $this->travel(1)->days(); // day 3
        $this->artisan('sitevaulta:reverify-domains')->expectsOutputToContain('reminder 1');
        $this->travel(1)->days(); // day 4
        $this->artisan('sitevaulta:reverify-domains')->expectsOutputToContain('waiting 1');
        $this->travel(1)->days(); // day 5
        $this->artisan('sitevaulta:reverify-domains')->expectsOutputToContain('reminder 1');
        $this->travel(1)->days(); // day 6
        $this->artisan('sitevaulta:reverify-domains')->expectsOutputToContain('reminder 1');
        $this->assertTrue($website->fresh()->isVerified());

        $this->travel(1)->days(); // day 7
        $this->artisan('sitevaulta:reverify-domains')->expectsOutputToContain('lapsed 1');

        $this->assertFalse($website->fresh()->isVerified(), 'Backups are paused.');
        $this->assertNotNull(VerifiedDomain::sole()->ownership_lapsed_at);
        $this->assertSame([
            'Verification token missing on shop.example',
            '4 days left to restore the token on shop.example',
            '2 days left to restore the token on shop.example',
            '1 day left to restore the token on shop.example',
            'Backups of Shop paused',
        ], $this->notificationTitles());
    }

    public function test_lists_show_the_countdown_while_the_token_is_missing(): void
    {
        $this->verifiedWebsite();
        $this->siteState = 'token_gone';
        $this->travel(1)->days();
        $this->artisan('sitevaulta:reverify-domains');

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/websites")
            ->assertOk()
            ->assertSee('Token missing — backups pause in 7 days');

        $this->get("/app/{$this->workspace->slug}/verified-domains")
            ->assertOk()
            ->assertSeeInOrder(['shop.example', 'Token missing', 'Put it back by', '7 days left']);
    }

    public function test_an_unreachable_site_or_failed_dns_never_starts_the_countdown(): void
    {
        $this->verifiedWebsite();

        foreach (['site_down', 'dns_down'] as $state) {
            $this->siteState = $state;
            $this->travel(1)->days();
            $this->artisan('sitevaulta:reverify-domains')->expectsOutputToContain('inconclusive 1');
        }

        $this->assertNull(VerifiedDomain::sole()->token_missing_since);
        $this->assertSame([], $this->notificationTitles());
    }

    public function test_putting_the_token_back_during_the_grace_period_clears_the_countdown(): void
    {
        $website = $this->verifiedWebsite();
        $this->siteState = 'token_gone';
        $this->travel(1)->days();
        $this->artisan('sitevaulta:reverify-domains')->expectsOutputToContain('notice 1');

        $this->siteState = 'token_back';
        $this->travel(1)->days();
        $this->artisan('sitevaulta:reverify-domains')->expectsOutputToContain('restored 1');

        $row = VerifiedDomain::sole();
        $this->assertNull($row->token_missing_since);
        $this->assertTrue($website->fresh()->isVerified());
        $this->assertSame('Verification token found again on shop.example', $this->notificationTitles()[1]);
    }

    public function test_verify_now_cannot_postpone_the_deadline_but_a_found_token_resumes_backups_at_once(): void
    {
        $website = $this->verifiedWebsite();
        $this->siteState = 'token_gone';
        $this->travel(1)->days();
        $this->artisan('sitevaulta:reverify-domains');

        // A failed manual attempt neither revokes nor resets the countdown, and the row is still re-checked.
        $this->assertFalse(app(HostOwnershipVerifier::class)->verify(VerifiedDomain::sole()));
        $this->assertTrue(VerifiedDomain::sole()->isInGracePeriod());
        $this->travel(7)->days();
        $this->artisan('sitevaulta:reverify-domains')->expectsOutputToContain('lapsed 1');
        $this->assertFalse($website->fresh()->isVerified());

        $this->siteState = 'token_back';
        $this->assertTrue(app(HostOwnershipVerifier::class)->verify(VerifiedDomain::sole()));
        $this->assertTrue($website->fresh()->isVerified(), 'Backups resume immediately.');
        $this->assertNull(VerifiedDomain::sole()->ownership_lapsed_at);
    }

    public function test_the_whole_run_is_skipped_while_the_probe_has_no_network(): void
    {
        $this->verifiedWebsite();
        $this->siteState = 'token_gone';
        config(['monitoring.control_hosts' => ['control.example']]);
        $this->mock(NetworkProbe::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveIps')->andReturn([]);
        });

        $this->travel(1)->days();
        $this->artisan('sitevaulta:reverify-domains')->expectsOutput('Skipped: the monitoring probe has no network right now.');

        $this->assertNull(VerifiedDomain::sole()->token_missing_since);
    }
}
