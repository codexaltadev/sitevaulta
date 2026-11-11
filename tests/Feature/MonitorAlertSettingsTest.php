<?php

namespace Tests\Feature;

use App\Enums\MonitorIncidentStatus;
use App\Enums\NotificationChannelType;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Monitoring\IncidentAlertNotifier;
use App\Services\Monitoring\MonitorCheckRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitorAlertSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function eventTypes(MonitorIncident $incident): array
    {
        return $incident->events()->orderBy('id')->pluck('type')->all();
    }

    public function test_delayed_alert_is_held_when_the_incident_opens(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://shop.test/' => Http::response('down', 503)]);
        $monitor = Monitor::factory()->up()->create(['url' => 'https://shop.test/', 'alert_delay_minutes' => 5]);

        app(MonitorCheckRunner::class)->run($monitor);

        $incident = MonitorIncident::sole();
        $this->assertNull($incident->alerted_at);
        $this->assertSame(['detected', 'confirmed', 'alert_delayed'], $this->eventTypes($incident));
        $this->assertSame(0, User::find($monitor->workspace->owner_id)->notifications()->count());
    }

    public function test_held_alert_is_sent_once_the_delay_has_passed(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->down()->create(['alert_delay_minutes' => 5]);
        $incident = MonitorIncident::factory()->for($monitor)->notYetAlerted()->create(['started_at' => now()->subMinutes(4)]);
        $notifier = app(IncidentAlertNotifier::class);

        $this->assertSame(0, $notifier->sendDueAlerts());

        $this->travel(1)->minutes();
        $this->assertSame(1, $notifier->sendDueAlerts());

        $this->assertNotNull($incident->refresh()->alerted_at);
        $this->assertSame(['alert_sent'], $this->eventTypes($incident));
        $this->assertSame(1, User::find($monitor->workspace->owner_id)->notifications()->count());
    }

    public function test_recovery_inside_the_delay_alerts_nobody(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://shop.test/' => Http::response('ok', 200)]);
        $monitor = Monitor::factory()->down()->create(['url' => 'https://shop.test/', 'alert_delay_minutes' => 10]);
        $incident = MonitorIncident::factory()->for($monitor)->notYetAlerted()->create(['started_at' => now()->subMinutes(3)]);

        app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame(MonitorIncidentStatus::Resolved, $incident->refresh()->status);
        $this->assertSame(['resolved', 'alert_skipped'], $this->eventTypes($incident));
        $this->assertSame(0, User::find($monitor->workspace->owner_id)->notifications()->count());
    }

    public function test_reminders_repeat_while_the_monitor_stays_down(): void
    {
        $this->freezeTime();
        $monitor = Monitor::factory()->down()->create(['alert_repeat_minutes' => 15]);
        $incident = MonitorIncident::factory()->for($monitor)->create(['started_at' => now()->subMinutes(14)]);
        $notifier = app(IncidentAlertNotifier::class);

        $this->assertSame(0, $notifier->sendDueAlerts());

        $this->travel(1)->minutes();
        $this->assertSame(1, $notifier->sendDueAlerts());
        $this->assertSame(0, $notifier->sendDueAlerts());

        $this->travel(15)->minutes();
        $this->assertSame(1, $notifier->sendDueAlerts());

        $this->assertSame(2, $incident->refresh()->reminder_count);
    }

    public function test_no_reminders_when_repeat_is_off_or_monitor_is_paused(): void
    {
        $this->freezeTime();
        $quiet = Monitor::factory()->down()->create(['alert_repeat_minutes' => 0]);
        MonitorIncident::factory()->for($quiet)->create(['started_at' => now()->subDay()]);
        $paused = Monitor::factory()->paused()->create(['alert_repeat_minutes' => 5]);
        MonitorIncident::factory()->for($paused)->create(['started_at' => now()->subDay()]);

        $this->assertSame(0, app(IncidentAlertNotifier::class)->sendDueAlerts());
    }

    public function test_alerts_go_only_to_selected_channels_of_the_same_workspace(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://hooks.test/*' => Http::response('ok')]);
        $monitor = Monitor::factory()->down()->create(['notify_workspace_members' => false]);
        $selected = NotificationChannel::create([
            'workspace_id' => $monitor->workspace_id,
            'name' => 'Ops webhook',
            'type' => NotificationChannelType::Webhook,
            'config' => ['webhook_url' => 'https://hooks.test/selected'],
            'events' => [],
            'is_active' => true,
        ]);
        NotificationChannel::create([
            'workspace_id' => $monitor->workspace_id,
            'name' => 'Marketing webhook',
            'type' => NotificationChannelType::Webhook,
            'config' => ['webhook_url' => 'https://hooks.test/unselected'],
            'events' => [],
            'is_active' => true,
        ]);
        $foreignMonitor = Monitor::factory()->create();
        $foreign = NotificationChannel::create([
            'workspace_id' => $foreignMonitor->workspace_id,
            'name' => 'Other tenant',
            'type' => NotificationChannelType::Webhook,
            'config' => ['webhook_url' => 'https://hooks.test/foreign'],
            'events' => [],
            'is_active' => true,
        ]);
        $monitor->update(['alert_channel_ids' => [$selected->id, $foreign->id]]);
        $incident = MonitorIncident::factory()->for($monitor)->notYetAlerted()->create(['started_at' => now()->subMinute()]);

        app(IncidentAlertNotifier::class)->sendDueAlerts();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.test/selected');
        $this->assertSame(0, User::find($monitor->workspace->owner_id)->notifications()->count());
        $this->assertStringContainsString('2 selected alert channel(s)', $incident->events()->first()->message);
    }
}
