<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneMonitorDataCommand extends Command
{
    private const CHUNK = 5000;

    protected $signature = 'sitevaulta:prune-monitor-data';

    protected $description = "Delete uptime checks and hourly/daily totals older than each workspace's plan retention (set per plan in the Admin panel).";

    public function handle(): int
    {
        $now = now()->utc();
        $deleted = ['raw checks' => 0, 'hourly totals' => 0, 'daily totals' => 0];

        Workspace::query()
            ->whereHas('monitors')
            ->with('currentSubscription.plan')
            ->lazyById(100)
            ->each(function (Workspace $workspace) use ($now, &$deleted): void {
                $retention = EntitlementService::monitorRetention($workspace);
                $monitorIds = $workspace->monitors()->pluck('id')->all();

                $deleted['raw checks'] += $this->deleteInChunks('monitor_checks', 'checked_at', $monitorIds, $now->copy()->subDays($retention['check_log_days']));
                $deleted['hourly totals'] += $this->deleteInChunks('monitor_hourly_stats', 'period_start', $monitorIds, $now->copy()->subDays($retention['hourly_days'])->startOfHour());
                $deleted['daily totals'] += $this->deleteInChunks('monitor_daily_stats', 'period_start', $monitorIds, $now->copy()->subDays($retention['history_days'])->toDateString());
            });

        foreach ($deleted as $label => $count) {
            $this->info("Deleted {$count} {$label}");
        }

        return self::SUCCESS;
    }

    /**
     * Small batches keep each delete short, so checks writing at the same time are never blocked for long.
     *
     * @param  list<int>  $monitorIds
     */
    private function deleteInChunks(string $table, string $column, array $monitorIds, mixed $cutoff): int
    {
        $total = 0;

        foreach (array_chunk($monitorIds, 500) as $monitorChunk) {
            do {
                $ids = DB::table($table)->whereIn('monitor_id', $monitorChunk)->where($column, '<', $cutoff)->orderBy('id')->limit(self::CHUNK)->pluck('id');
                $total += $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
            } while ($ids->count() === self::CHUNK);
        }

        return $total;
    }
}
