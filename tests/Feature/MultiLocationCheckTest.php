<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\ProbeLocation;
use App\Models\User;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\ProbeSignature;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MultiLocationCheckTest extends TestCase
{
    use RefreshDatabase;

    private Monitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monitor = Monitor::factory()->up()->create(['name' => 'Shop', 'url' => 'https://shop.test/']);
        ProbeLocation::factory()->create(['name' => 'Singapore', 'url' => 'https://sg.probe.test']);
        ProbeLocation::factory()->create(['name' => 'Frankfurt', 'url' => 'https://fra.probe.test']);
    }

    /**
     * The site is down from here; the agents answer with the given verdicts (null = the agent is broken).
     */
    private function fakeAgents(?string $singapore, ?string $frankfurt): void
    {
        $answer = fn (?string $verdict) => $verdict === null
            ? Http::response('oops', 500)
            : Http::response(['verdict' => $verdict, 'error' => $verdict === 'down' ? 'HTTP 503 Service Unavailable' : null]);

        Http::fake([
            'https://shop.test/*' => Http::response('down', 503),
            'https://sg.probe.test/probe-agent/check' => $answer($singapore),
            'https://fra.probe.test/probe-agent/check' => $answer($frankfurt),
        ]);
    }

    public function test_an_outage_confirmed_by_the_other_locations_opens_an_incident(): void
    {
        $this->fakeAgents('down', 'down');

        app(MonitorCheckRunner::class)->run($this->monitor);

        $this->assertSame(MonitorStatus::Down, $this->monitor->refresh()->status);
        $this->assertSame(
            'HTTP 503 Service Unavailable confirmed by re-check and from Singapore, Frankfurt',
            MonitorIncident::sole()->events()->where('type', 'confirmed')->value('message'),
        );
    }

    public function test_a_failure_seen_from_one_location_only_is_regional_not_an_outage(): void
    {
        $this->fakeAgents('up', 'up');

        $check = app(MonitorCheckRunner::class)->run($this->monitor);

        $this->monitor->refresh();
        $this->assertSame(MonitorStatus::Up, $this->monitor->status);
        $this->assertSame(0, MonitorIncident::count());
        $this->assertSame('Not reachable from Primary probe (HTTP 503 Service Unavailable); OK from Singapore, Frankfurt', $this->monitor->last_regional_note);
        $this->assertSame(MonitorStatus::Up, $check->status);
        $this->assertNull($check->response_time_ms, 'The failed attempt\'s timing must not enter the response chart.');
    }

    public function test_the_majority_decides_a_split_vote(): void
    {
        $this->fakeAgents('down', 'up');

        app(MonitorCheckRunner::class)->run($this->monitor);

        $this->assertSame(MonitorStatus::Down, $this->monitor->refresh()->status, '2 of 3 locations see it down.');
        $this->assertNull($this->monitor->last_regional_note);
    }

    public function test_without_answering_agents_this_servers_verdict_stands(): void
    {
        $this->fakeAgents(null, null);

        app(MonitorCheckRunner::class)->run($this->monitor);

        $this->assertSame(MonitorStatus::Down, $this->monitor->refresh()->status);
    }

    public function test_agents_known_to_be_offline_are_not_asked_and_up_sites_never_ask(): void
    {
        ProbeLocation::query()->update(['health_status' => ProbeLocation::UNREACHABLE]);
        $this->fakeAgents('up', 'up');

        app(MonitorCheckRunner::class)->run($this->monitor);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'probe-agent'));
        $this->assertSame(MonitorStatus::Down, $this->monitor->refresh()->status);

        Http::fake(['https://shop.test/*' => Http::response('ok')]);
        app(MonitorCheckRunner::class)->run($this->monitor);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'probe-agent'));
    }

    public function test_the_agent_only_answers_signed_requests_in_agent_mode(): void
    {
        $body = json_encode(['type' => 'http', 'url' => 'https://shop.test/', 'timeout_seconds' => 10]);
        $post = fn (array $headers) => $this->call('POST', '/probe-agent/check', [], [], [], $this->transformHeadersToServerVars([...$headers, 'Content-Type' => 'application/json', 'Accept' => 'application/json']), $body);

        $this->assertSame(404, $post(ProbeSignature::headers('agent-secret', $body))->status(), 'Not an agent: the endpoint does not exist.');

        config(['monitoring.agent.mode' => true, 'monitoring.agent.secret' => 'agent-secret']);

        $this->assertSame(401, $post(ProbeSignature::headers('wrong-secret', $body))->status());
        $this->assertSame(401, $post(ProbeSignature::headers('agent-secret', $body, time() - 300))->status(), 'A replayed old request is refused.');

        Http::fake(['https://shop.test/*' => Http::response('down', 503)]);
        $response = $post(ProbeSignature::headers('agent-secret', $body));

        $response->assertOk()->assertJson(['verdict' => 'down', 'http_status' => 503, 'error' => 'HTTP 503 Service Unavailable']);
    }

    public function test_the_agent_refuses_checks_that_do_not_depend_on_location(): void
    {
        config(['monitoring.agent.mode' => true, 'monitoring.agent.secret' => 'agent-secret']);
        $body = json_encode(['type' => 'domain', 'host' => 'shop.test', 'timeout_seconds' => 10]);

        $this->call('POST', '/probe-agent/check', [], [], [], $this->transformHeadersToServerVars([...ProbeSignature::headers('agent-secret', $body), 'Content-Type' => 'application/json', 'Accept' => 'application/json']), $body)
            ->assertStatus(422);
    }

    public function test_the_health_check_marks_agents_and_tells_admins_when_one_goes_offline(): void
    {
        $admin = User::factory()->create(['can_access_admin_panel' => true]);
        Http::fake([
            'https://sg.probe.test/probe-agent/health' => Http::response(['ok' => true, 'network' => true]),
            'https://fra.probe.test/probe-agent/health' => Http::response('Invalid probe signature.', 401),
        ]);

        $this->artisan('sitevaulta:check-probe-locations')->expectsOutput('Probe locations healthy: 1 of 2');

        $this->assertSame(ProbeLocation::HEALTHY, ProbeLocation::where('name', 'Singapore')->value('health_status'));
        $frankfurt = ProbeLocation::where('name', 'Frankfurt')->sole();
        $this->assertSame(ProbeLocation::UNREACHABLE, $frankfurt->health_status);
        $this->assertStringContainsString('secret on the agent does not match', $frankfurt->last_error);
        $this->assertSame(['Probe location Frankfurt is not answering'], $admin->notifications()->pluck('data')->pluck('title')->all());
    }

    public function test_an_agent_server_exposes_only_its_probe_endpoints_and_needs_no_database(): void
    {
        config(['monitoring.agent.mode' => true, 'monitoring.agent.secret' => 'agent-secret']);

        $this->get('/')->assertNotFound();
        $this->get('/app/login')->assertNotFound();
        $this->get('/status/anything')->assertNotFound();

        $body = json_encode(['ping' => true]);
        $this->call('POST', '/probe-agent/health', [], [], [], $this->transformHeadersToServerVars([...ProbeSignature::headers('agent-secret', $body), 'Content-Type' => 'application/json', 'Accept' => 'application/json']), $body)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame('array', config('session.driver'));
        $this->assertSame('file', config('cache.default'));
    }

    public function test_every_scheduled_task_runs_on_one_server_only(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertTrue($event->onOneServer, "{$event->command} must run on one server only.");
        }
    }

    public function test_admins_manage_locations_and_their_ips_are_published(): void
    {
        $admin = User::factory()->create(['can_access_admin_panel' => true]);
        ProbeLocation::where('name', 'Singapore')->update(['public_ips' => ['203.0.113.10']]);

        $this->actingAs($admin)->get('/admin/probe-locations')->assertOk()->assertSee('Singapore')->assertSee('Frankfurt');
        $this->get('/admin/probe-locations/create')->assertOk()->assertSee('MONITORING_AGENT_SECRET');
        $this->get(route('monitoring.ips'))->assertOk()->assertSee('203.0.113.10');
    }
}
