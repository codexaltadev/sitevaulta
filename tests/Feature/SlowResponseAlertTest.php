<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Filament\App\Resources\Monitors\Pages\EditMonitor;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Monitoring\SlowResponseWatcher;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SlowResponseAlertTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Monitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $this->user->id, 'billing_currency' => 'BDT', 'timezone' => 'UTC']);
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);
        $this->monitor = Monitor::factory()->up()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Shop',
            'url' => 'https://shop.test/',
            'slow_threshold_ms' => 1500,
            'slow_alert_after_checks' => 3,
        ]);
    }

    private function observe(int ...$responseTimes): void
    {
        foreach ($responseTimes as $milliseconds) {
            app(SlowResponseWatcher::class)->observe($this->monitor, $milliseconds);
        }
    }

    /**
     * @return list<string>
     */
    private function alertTitles(): array
    {
        return $this->user->notifications()->get()->sortBy('created_at')->pluck('data.title')->values()->all();
    }

    public function test_three_slow_checks_in_a_row_alert_once_without_an_incident(): void
    {
        $this->observe(2400, 2600, 2500, 2700);

        $this->assertSame(['🐢 Shop is slow'], $this->alertTitles());
        $this->assertSame(4, $this->monitor->consecutive_slow_checks);
        $this->assertNotNull($this->monitor->slow_alerted_at);
        $this->assertSame(MonitorStatus::Up, $this->monitor->refresh()->status);
        $this->assertSame(0, $this->monitor->incidents()->count());
    }

    public function test_a_short_slow_spell_does_not_alert(): void
    {
        $this->observe(2400, 2600, 300, 2400);

        $this->assertSame([], $this->alertTitles());
        $this->assertSame(1, $this->monitor->consecutive_slow_checks);
    }

    public function test_back_under_the_limit_sends_back_to_normal(): void
    {
        $this->observe(2400, 2600, 2500, 400);

        $this->assertSame(['🐢 Shop is slow', '⚡ Shop is fast again'], $this->alertTitles());
        $this->assertNull($this->monitor->slow_alerted_at);
    }

    public function test_no_threshold_means_no_slow_alerts(): void
    {
        $this->monitor->update(['slow_threshold_ms' => null]);

        $this->observe(9000, 9000, 9000, 9000);

        $this->assertSame([], $this->alertTitles());
    }

    public function test_slow_badge_shows_on_the_list_and_the_limit_is_saved_from_the_form(): void
    {
        $this->observe(2400, 2600, 2500);
        $this->monitor->update(['last_response_time_ms' => 2500]);

        $this->actingAs($this->user)
            ->get("/app/{$this->workspace->slug}/monitors")
            ->assertOk()
            ->assertSee('🐢 SLOW 2500ms');

        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->workspace);
        Filament::bootCurrentPanel();

        Livewire::test(EditMonitor::class, ['record' => $this->monitor->id])
            ->assertFormSet(['slow_threshold_ms' => 1500, 'slow_alert_after_checks' => 3])
            ->fillForm(['slow_threshold_ms' => 800, 'slow_alert_after_checks' => 2])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([800, 2], [$this->monitor->refresh()->slow_threshold_ms, $this->monitor->slow_alert_after_checks]);
    }
}
