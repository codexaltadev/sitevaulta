<?php

namespace App\Jobs\Monitoring;

use App\Models\Monitor;
use App\Services\Monitoring\MonitorCheckRunner;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One uptime check, run by a monitoring queue worker so many checks run in parallel and a slow site
 * never delays the others. Unique per monitor (never queued twice), and checks of the same host run one
 * at a time so we never hammer a site that many workspaces monitor.
 */
#[UniqueFor(600)]
class RunMonitorCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Two HTTP attempts, the confirmation pause and up to ten port probes fit well inside this.
     */
    public int $timeout = 360;

    /**
     * Waiting for another check of the same host is not a failure; a real exception is reported once.
     */
    public int $maxExceptions = 1;

    private ?Monitor $monitor = null;

    public function __construct(public int $monitorId)
    {
        $this->onQueue((string) config('monitoring.queue', 'monitoring'));
    }

    public function uniqueId(): string
    {
        return (string) $this->monitorId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $host = $this->monitor()?->ownershipHost() ?? "monitor-{$this->monitorId}";

        return [(new WithoutOverlapping("monitor-host:{$host}"))->releaseAfter(5)->expireAfter($this->timeout + 30)];
    }

    public function handle(MonitorCheckRunner $runner): void
    {
        $monitor = $this->monitor();

        // Deleted, paused, already checked by a duplicate, or in planned maintenance: nothing to do.
        if ($monitor === null || ! $monitor->isDueForCheck() || $monitor->isUnderMaintenance()) {
            return;
        }

        $runner->run($monitor);
    }

    private function monitor(): ?Monitor
    {
        return $this->monitor ??= Monitor::query()->with(['workspace', 'maintenanceWindows'])->find($this->monitorId);
    }
}
