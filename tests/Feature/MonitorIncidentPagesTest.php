<?php

namespace Tests\Feature;

use App\Filament\App\Resources\MonitorIncidents\Pages\ListMonitorIncidents;
use App\Filament\App\Resources\MonitorIncidents\Pages\ViewMonitorIncident;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MonitorIncidentPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Monitor $monitor;

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
        $this->monitor = Monitor::factory()->down()->create(['workspace_id' => $this->workspace->id, 'name' => 'shop.test']);
    }

    private function bootAppPanelForWorkspace(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->workspace);
        Filament::bootCurrentPanel();
    }

    public function test_incidents_list_shows_only_this_workspaces_incidents(): void
    {
        MonitorIncident::factory()->for($this->monitor)->create(['root_cause' => 'DNS Resolving problem']);
        MonitorIncident::factory()->create(['root_cause' => 'Someone else outage']);

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/incidents")
            ->assertOk()
            ->assertSee('DNS Resolving problem')
            ->assertSee('shop.test')
            ->assertDontSee('Someone else outage');
    }

    public function test_incidents_list_filters_by_status(): void
    {
        $ongoing = MonitorIncident::factory()->for($this->monitor)->create();
        $resolved = MonitorIncident::factory()->for($this->monitor)->resolved()->create(['started_at' => now()->subDay()]);
        $this->actingAs($this->user);
        $this->bootAppPanelForWorkspace();

        Livewire::test(ListMonitorIncidents::class)
            ->filterTable('status', 'resolved')
            ->assertCanSeeTableRecords([$resolved])
            ->assertCanNotSeeTableRecords([$ongoing]);
    }

    public function test_incident_detail_shows_request_response_and_activity_log_escaped(): void
    {
        $incident = MonitorIncident::factory()->for($this->monitor)->create([
            'root_cause' => 'HTTP 502 Bad Gateway',
            'request_snapshot' => ['method' => 'GET', 'url' => 'https://shop.test/', 'headers' => ['User-Agent' => 'SiteVaulta-Monitor/1.0']],
            'response_headers' => ['server' => ['nginx']],
            'response_body' => '<script>alert("pwned")</script>',
        ]);
        $incident->events()->create(['type' => 'detected', 'message' => 'HTTP 502 Bad Gateway detected', 'occurred_at' => now()]);

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/incidents/{$incident->id}")
            ->assertOk()
            ->assertSee('Ongoing incident on shop.test')
            ->assertSee('GET https://shop.test/')
            ->assertSee('User-Agent: SiteVaulta-Monitor/1.0')
            ->assertSee('server: nginx')
            ->assertSee('HTTP 502 Bad Gateway detected')
            ->assertSee('&lt;script&gt;alert(&quot;pwned&quot;)&lt;/script&gt;', escape: false)
            ->assertDontSee('<script>alert("pwned")</script>', escape: false);
    }

    public function test_incident_of_another_workspace_is_not_found(): void
    {
        $foreignIncident = MonitorIncident::factory()->create();

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/incidents/{$foreignIncident->id}")
            ->assertNotFound();
    }

    public function test_incident_can_be_excluded_from_reports(): void
    {
        $incident = MonitorIncident::factory()->for($this->monitor)->create();
        $this->actingAs($this->user);
        $this->bootAppPanelForWorkspace();

        Livewire::test(ViewMonitorIncident::class, ['record' => $incident->id])
            ->callAction('toggleVisibility');

        $this->assertTrue($incident->refresh()->is_excluded_from_reports);
    }

    public function test_download_response_returns_json_snapshot(): void
    {
        $incident = MonitorIncident::factory()->for($this->monitor)->create([
            'response_body' => 'Bad Gateway',
        ]);
        $this->actingAs($this->user);
        $this->bootAppPanelForWorkspace();

        Livewire::test(ViewMonitorIncident::class, ['record' => $incident->id])
            ->callAction('downloadResponse')
            ->assertFileDownloaded("incident-{$incident->id}-response.json");
    }
}
