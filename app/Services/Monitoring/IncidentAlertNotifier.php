<?php

namespace App\Services\Monitoring;

use App\Enums\MonitorIncidentStatus;
use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Services\Notifications\WorkspaceAlertDispatcher;
use Throwable;

/**
 * Applies each monitor's alert rules to its incidents:
 *  - alert_delay_minutes: hold the down alert until the outage has lasted this long (0 = immediately);
 *    an outage that recovers inside the delay alerts nobody, not even with a recovery message.
 *  - alert_repeat_minutes: remind every N minutes while still down (0 = never).
 *  - alert_channel_ids / notify_workspace_members: who receives the alerts.
 */
class IncidentAlertNotifier
{
    public function incidentOpened(Monitor $monitor, MonitorIncident $incident): void
    {
        if ($monitor->alert_delay_minutes === 0) {
            $this->deliver($monitor, $incident, 'down');

            return;
        }

        $incident->events()->create([
            'type' => 'alert_delayed',
            'message' => "Alert held for {$monitor->alert_delay_minutes} min — sent only if the monitor is still down then",
            'occurred_at' => now()->utc(),
        ]);
    }

    public function incidentResolved(Monitor $monitor, MonitorIncident $incident): void
    {
        if ($incident->alerted_at === null) {
            $incident->events()->create([
                'type' => 'alert_skipped',
                'message' => 'Recovered within the alert delay — no alerts were sent',
                'occurred_at' => now()->utc(),
            ]);

            return;
        }

        $this->deliver($monitor, $incident, 'recovery');
    }

    /**
     * Sends delayed down alerts and repeat reminders that have come due. Runs every minute from the scheduler.
     *
     * @return int number of alerts sent
     */
    public function sendDueAlerts(): int
    {
        $sentCount = 0;
        $now = now()->utc();

        MonitorIncident::query()
            ->where('status', MonitorIncidentStatus::Ongoing)
            ->with(['monitor.workspace', 'monitor.website', 'monitor.maintenanceWindows'])
            ->lazyById(100)
            ->each(function (MonitorIncident $incident) use ($now, &$sentCount): void {
                $monitor = $incident->monitor;

                if ($monitor === null || $monitor->status === MonitorStatus::Paused || $monitor->isUnderMaintenance($now)) {
                    return;
                }

                if ($incident->alerted_at === null) {
                    if ($incident->started_at->copy()->addMinutes($monitor->alert_delay_minutes)->lte($now)) {
                        $this->deliver($monitor, $incident, 'down');
                        $sentCount++;
                    }

                    return;
                }

                $lastSent = $incident->last_reminder_at ?? $incident->alerted_at;

                if ($monitor->alert_repeat_minutes > 0 && $lastSent->copy()->addMinutes($monitor->alert_repeat_minutes)->lte($now)) {
                    $this->deliver($monitor, $incident, 'reminder');
                    $sentCount++;
                }
            });

        return $sentCount;
    }

    /**
     * @param  'down'|'reminder'|'recovery'  $kind
     */
    private function deliver(Monitor $monitor, MonitorIncident $incident, string $kind): void
    {
        $workspace = $monitor->workspace;

        if ($workspace === null) {
            return;
        }

        $isDown = $kind !== 'recovery';
        $now = now()->utc();
        $downFor = $this->formatDuration($incident->duration_seconds ?? (int) $incident->started_at->diffInSeconds($now));
        $deliveryStatus = 'success';
        $websiteEmailSent = false;

        [$title, $body, $status] = match ($kind) {
            'down' => ["🔴 {$monitor->name} is down", $incident->root_cause, 'danger'],
            'reminder' => ["🔴 {$monitor->name} is still down", "{$incident->root_cause} — down for {$downFor}", 'danger'],
            'recovery' => ["🟢 {$monitor->name} is back up", "Incident resolved after {$downFor}", 'success'],
        };

        try {
            if ($monitor->notify_workspace_members) {
                WorkspaceAlertDispatcher::sendDatabaseNotificationToWorkspace($workspace, $title, $body, $status);
            }

            WorkspaceAlertDispatcher::dispatch(
                $workspace,
                $isDown ? 'monitor_down' : 'monitor_up',
                [
                    'monitor_name' => $monitor->name,
                    'target' => $monitor->targetLabel(),
                    'root_cause' => $incident->root_cause,
                    'duration' => $downFor,
                    'reminder' => $kind === 'reminder',
                ],
                $monitor->alert_channel_ids ?: null,
            );

            $websiteEmailSent = $monitor->website !== null && WorkspaceAlertDispatcher::sendWebsiteUptimeAlertEmail(
                website: $monitor->website,
                isDown: $isDown,
                reason: $isDown ? $body : "Website recovered after {$downFor}",
                httpStatus: $monitor->last_http_status_code,
                responseTimeMs: $monitor->last_response_time_ms,
            );
        } catch (Throwable $exception) {
            report($exception);
            $deliveryStatus = 'failed';
        }

        $incident->forceFill(match ($kind) {
            'down' => ['alerted_at' => $now],
            'reminder' => ['last_reminder_at' => $now, 'reminder_count' => $incident->reminder_count + 1],
            'recovery' => [],
        })->save();

        $recipients = collect([
            $monitor->notify_workspace_members ? 'workspace members' : null,
            $monitor->alert_channel_ids ? count($monitor->alert_channel_ids).' selected alert channel(s)' : 'all active alert channels',
            $websiteEmailSent ? 'the website uptime alert email' : null,
        ])->filter()->implode(', ');

        $incident->events()->create([
            'type' => 'alert_sent',
            'message' => match ($kind) {
                'down' => 'Down alert',
                'reminder' => 'Reminder #'.($incident->reminder_count).' (still down)',
                'recovery' => 'Recovery alert',
            }." sent to {$recipients}",
            'delivery_status' => $deliveryStatus,
            'occurred_at' => $now,
        ]);
    }

    private function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return "{$hours}h {$minutes}m ".($seconds % 60).'s';
    }
}
