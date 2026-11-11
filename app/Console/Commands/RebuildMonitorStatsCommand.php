<?php

namespace App\Console\Commands;

use App\Services\Monitoring\MonitorStatsRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildMonitorStatsCommand extends Command
{
    protected $signature = 'sitevaulta:rebuild-monitor-stats {--monitor= : Only this monitor id}';

    protected $description = 'Recompute the hourly and daily uptime totals from the raw checks that are still kept.';

    public function handle(MonitorStatsRecorder $recorder): int
    {
        $hours = DB::table('monitor_checks')
            ->when($this->option('monitor'), fn ($query, $monitorId) => $query->where('monitor_id', (int) $monitorId))
            ->orderBy('monitor_id')
            ->orderBy('checked_at')
            ->get(['monitor_id', 'checked_at'])
            ->map(fn (object $check): array => [(int) $check->monitor_id, CarbonImmutable::parse($check->checked_at, 'UTC')->startOfHour()])
            ->unique(fn (array $hour): string => $hour[0].'|'.$hour[1]->toDateTimeString());

        $days = [];

        foreach ($hours as [$monitorId, $hourStart]) {
            $recorder->rebuildHour($monitorId, $hourStart);
            $days[$monitorId.'|'.$hourStart->toDateString()] = [$monitorId, $hourStart];
        }

        foreach ($days as [$monitorId, $dayMoment]) {
            $recorder->rebuildDay($monitorId, $dayMoment);
        }

        $this->info('Rebuilt '.$hours->count().' hourly and '.count($days).' daily rows');

        return self::SUCCESS;
    }
}
