<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneMonitorDataCommand extends Command
{
    private const CHUNK = 5000;

    protected $signature = 'sitevaulta:prune-monitor-data';

    protected $description = 'Delete raw uptime checks and hourly/daily totals older than the configured retention.';

    public function handle(): int
    {
        $now = now()->utc();

        $deleted = [
            'raw checks' => $this->deleteInChunks('monitor_checks', 'checked_at', $now->copy()->subDays((int) config('monitoring.retention.raw_days', 30))),
            'hourly totals' => $this->deleteInChunks('monitor_hourly_stats', 'period_start', $now->copy()->subDays((int) config('monitoring.retention.hourly_days', 90))->startOfHour()),
            'daily totals' => $this->deleteInChunks('monitor_daily_stats', 'period_start', $now->copy()->subDays((int) config('monitoring.retention.daily_days', 730))->toDateString()),
        ];

        foreach ($deleted as $label => $count) {
            $this->info("Deleted {$count} {$label}");
        }

        return self::SUCCESS;
    }

    /**
     * Small batches keep each delete short, so checks writing at the same time are never blocked for long.
     */
    private function deleteInChunks(string $table, string $column, mixed $cutoff): int
    {
        $total = 0;

        do {
            $ids = DB::table($table)->where($column, '<', $cutoff)->orderBy('id')->limit(self::CHUNK)->pluck('id');
            $total += $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        return $total;
    }
}
