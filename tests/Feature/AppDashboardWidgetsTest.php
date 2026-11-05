<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DemoWorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppDashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_dashboard_renders_with_animated_widgets(): void
    {
        $this->seed();

        $user = User::where('email', 'demo@sitevaulta.com')->first();
        $workspace = Workspace::where('slug', 'demo-agency')->first();

        app(DemoWorkspaceSeeder::class)->seedDemoForWorkspace($workspace);

        $response = $this->actingAs($user)
            ->get('/app/' . $workspace->slug);

        file_put_contents('/tmp/dashboard_output.html', $response->getContent());
        echo "DASHBOARD HTML SAVED TO /tmp/dashboard_output.html\n";

        $response->assertStatus(200)
            ->assertSee('Mission Control')
            ->assertSee('Storage Footprint')
            ->assertSee('Recent Backup Activity Stream');
    }

    public function test_fresh_new_workspace_sees_clean_onboarding_state(): void
    {
        $this->seed();

        $freshUser = User::factory()->create(['email' => 'newuser@example.com']);
        $freshWorkspace = Workspace::create([
            'name' => 'Brand New Startup',
            'slug' => 'brand-new-startup',
            'owner_id' => $freshUser->id,
            'billing_currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
        ]);
        $freshWorkspace->members()->attach($freshUser->id, ['role' => 'owner']);

        $response = $this->actingAs($freshUser)
            ->get('/app/' . $freshWorkspace->slug);

        $response->assertStatus(200)
            ->assertSee('Welcome to SiteVaulta Mission Control')
            ->assertSee('Protect Your First Website in 3 Simple Steps')
            ->assertSee('Populate Demo Data');
    }

    public function test_dashboard_displays_live_percentage_for_active_streaming_backup(): void
    {
        $this->seed();

        $user = User::where('email', 'demo@sitevaulta.com')->first();
        $workspace = Workspace::where('slug', 'demo-agency')->first();
        app(DemoWorkspaceSeeder::class)->seedDemoForWorkspace($workspace);

        $policy = \App\Models\BackupPolicy::where('workspace_id', $workspace->id)->first();
        $website = $policy->website;

        // Create an active backup run in Extracting stage (40%)
        $run = \App\Models\BackupRun::create([
            'workspace_id' => $workspace->id,
            'website_id' => $website->id,
            'backup_policy_id' => $policy->id,
            'trigger_type' => 'manual',
            'backup_type' => 'full',
            'scope' => \App\Enums\BackupScope::FullStack,
            'status' => \App\Enums\BackupRunStatus::Extracting,
            'started_at' => now()->utc()->subMinutes(2),
        ]);

        $this->assertEquals(40, $run->progressPercent());
        $this->assertStringContainsString('Extracting', $run->stageDescription());

        $response = $this->actingAs($user)
            ->get('/app/' . $workspace->slug);

        $response->assertStatus(200)
            ->assertSee('Live Server Queue Processing')
            ->assertSee('40%')
            ->assertSee('Extracting website files & database');
    }
}
