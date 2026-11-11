<?php

namespace App\Console\Commands;

use App\Enums\MonitorType;
use App\Jobs\Monitoring\RunMonitorCheckJob;
use App\Models\Monitor;
use App\Services\Monitoring\IncidentAlertNotifier;
use App\Services\Monitoring\MonitorCheckRunner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class CheckMonitorsCommand extends Command
{
    /**
     * A dispatched monitor is not picked again for this long, even if its job is still waiting in the queue
     * (the job itself is unique per monitor, so this only saves work). A finished run sets the real next time.
     */
    private const CLAIM_SECONDS = 120;

    protected $signature = 'sitevaulta:check-monitors {--sync : Run the checks in this process instead of queueing them}';

    protected $description = 'Queue a check for every uptime monitor whose next check time has come, and send due alerts.';

    public function handle(MonitorCheckRunner $runner, IncidentAlertNotifier $alertNotifier): int
    {
        $now = now()->utc();
        $runInline = (bool) $this->option('sync');
        $dueCount = 0;

        // Only monitors whose next_check_at has passed are loaded (an indexed lookup), not every monitor.
        Monitor::query()
            ->active()
            ->where(fn (Builder $query) => $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now))
            ->with(['workspace', 'maintenanceWindows'])
            ->lazyById(200)
            ->filter(function (Monitor $monitor): bool {
                $isDue = $monitor->isDueForCheck();

                // Not due yet but never scheduled (e.g. checked before next_check_at existed): schedule it,
                // so it is not re-read every minute until its next run.
                if (! $isDue && $monitor->next_check_at === null && $monitor->last_checked_at !== null && $monitor->type !== MonitorType::Heartbeat) {
                    $monitor->forceFill(['next_check_at' => $monitor->last_checked_at->copy()->addSeconds(max(30, (int) $monitor->interval_seconds))])->saveQuietly();
                }

                return $isDue && ! $monitor->isUnderMaintenance();
            })
            ->each(function (Monitor $monitor) use ($runner, $runInline, $now, &$dueCount): void {
                $dueCount++;

                if ($runInline) {
                    try {
                        $runner->run($monitor);
                    } catch (Throwable $exception) {
                        report($exception);
                    }

                    return;
                }

                $monitor->forceFill(['next_check_at' => $now->addSeconds(self::CLAIM_SECONDS)])->saveQuietly();
                RunMonitorCheckJob::dispatch($monitor->id);
            });

        $this->info(($runInline ? 'Checked' : 'Queued')." {$dueCount} due monitors");
        $this->info('Sent '.$alertNotifier->sendDueAlerts().' delayed alerts and reminders');

        return self::SUCCESS;
    }
}
