<?php

namespace App\Console\Commands;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Services\Monitoring\IncidentAlertNotifier;
use App\Services\Monitoring\MonitorCheckRunner;
use Illuminate\Console\Command;
use Throwable;

class CheckMonitorsCommand extends Command
{
    protected $signature = 'sitevaulta:check-monitors';

    protected $description = 'Run every standalone uptime monitor whose check interval has elapsed, recording checks and opening or resolving incidents.';

    public function handle(MonitorCheckRunner $runner, IncidentAlertNotifier $alertNotifier): int
    {
        $checkedCount = 0;
        $downCount = 0;

        Monitor::query()
            ->active()
            ->with(['workspace', 'maintenanceWindows'])
            ->lazyById(100)
            ->filter(fn (Monitor $monitor): bool => $monitor->isDueForCheck() && ! $monitor->isUnderMaintenance())
            ->each(function (Monitor $monitor) use ($runner, &$checkedCount, &$downCount): void {
                try {
                    $check = $runner->run($monitor);
                } catch (Throwable $exception) {
                    report($exception);

                    return;
                }

                $checkedCount++;

                if ($check->status === MonitorStatus::Down) {
                    $downCount++;
                }
            });

        $this->info("Checked {$checkedCount} due monitors | Down: {$downCount}");
        $this->info('Sent '.$alertNotifier->sendDueAlerts().' delayed alerts and reminders');

        return self::SUCCESS;
    }
}
