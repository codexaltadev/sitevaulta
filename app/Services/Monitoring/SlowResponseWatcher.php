<?php

namespace App\Services\Monitoring;

use App\Models\Monitor;
use App\Services\Notifications\WorkspaceAlertDispatcher;
use Throwable;

/**
 * Slow-site alert. A site that answers but slowly is still up — no incident, no uptime loss — yet the owner
 * wants to know. After `slow_alert_after_checks` successful checks in a row above `slow_threshold_ms` one
 * alert goes out; the first check back under the threshold sends "back to normal".
 */
class SlowResponseWatcher
{
    /**
     * Called after every successful check of the monitor.
     */
    public function observe(Monitor $monitor, ?int $responseTimeMs): void
    {
        $threshold = $monitor->slow_threshold_ms;

        if ($threshold === null || $threshold <= 0 || $responseTimeMs === null) {
            $this->reset($monitor, notifyRecovery: false);

            return;
        }

        if ($responseTimeMs <= $threshold) {
            $this->reset($monitor, notifyRecovery: true, responseTimeMs: $responseTimeMs);

            return;
        }

        $slowChecks = $monitor->consecutive_slow_checks + 1;
        $shouldAlert = $monitor->slow_alerted_at === null && $slowChecks >= max(1, $monitor->slow_alert_after_checks);

        $monitor->forceFill([
            'consecutive_slow_checks' => $slowChecks,
            'slow_alerted_at' => $shouldAlert ? now() : $monitor->slow_alerted_at,
        ])->saveQuietly();

        if ($shouldAlert) {
            $this->deliver($monitor, slow: true, responseTimeMs: $responseTimeMs);
        }
    }

    /**
     * A failed check is the incident's business; the slow streak simply starts over afterwards.
     */
    public function reset(Monitor $monitor, bool $notifyRecovery = false, ?int $responseTimeMs = null): void
    {
        $wasAlerted = $monitor->slow_alerted_at !== null;

        if ($monitor->consecutive_slow_checks === 0 && ! $wasAlerted) {
            return;
        }

        $monitor->forceFill(['consecutive_slow_checks' => 0, 'slow_alerted_at' => null])->saveQuietly();

        if ($wasAlerted && $notifyRecovery) {
            $this->deliver($monitor, slow: false, responseTimeMs: $responseTimeMs);
        }
    }

    private function deliver(Monitor $monitor, bool $slow, ?int $responseTimeMs): void
    {
        $workspace = $monitor->workspace;

        if ($workspace === null) {
            return;
        }

        $title = $slow ? "🐢 {$monitor->name} is slow" : "⚡ {$monitor->name} is fast again";
        $body = $slow
            ? "Responding in {$responseTimeMs} ms — above your {$monitor->slow_threshold_ms} ms limit for {$monitor->consecutive_slow_checks} checks in a row. The site is still up."
            : "Responding in {$responseTimeMs} ms again — back under your {$monitor->slow_threshold_ms} ms limit.";

        try {
            if ($monitor->notify_workspace_members) {
                WorkspaceAlertDispatcher::sendDatabaseNotificationToWorkspace($workspace, $title, $body, $slow ? 'warning' : 'success');
            }

            WorkspaceAlertDispatcher::dispatch(
                $workspace,
                $slow ? 'monitor_slow' : 'monitor_fast',
                [
                    'monitor_name' => $monitor->name,
                    'target' => $monitor->targetLabel(),
                    'response_time_ms' => $responseTimeMs,
                    'threshold_ms' => $monitor->slow_threshold_ms,
                ],
                $monitor->alert_channel_ids ?: null,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
