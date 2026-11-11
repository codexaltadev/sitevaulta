<?php

namespace App\Services\Monitoring;

use App\Enums\MonitorStatus;
use App\Models\MonitorCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Keeps monitor_hourly_stats and monitor_daily_stats in step with monitor_checks. Each row is recomputed
 * from its source (raw checks for the hour, hourly rows for the day), so recording is idempotent and a
 * rebuild after a bug or a backfill gives exactly the same numbers.
 *
 * Response times only count successful checks: a failed check's timing is not a page load.
 */
class MonitorStatsRecorder
{
    public function recordCheck(MonitorCheck $check): void
    {
        $checkedAt = CarbonImmutable::instance($check->checked_at)->utc();

        $this->rebuildHour($check->monitor_id, $checkedAt);
        $this->rebuildDay($check->monitor_id, $checkedAt);

        DB::table('monitors')
            ->where('id', $check->monitor_id)
            ->where(fn ($query) => $query->whereNull('first_checked_at')->orWhere('first_checked_at', '>', $checkedAt))
            ->update(['first_checked_at' => $checkedAt]);
    }

    public function rebuildHour(int $monitorId, CarbonImmutable $moment): void
    {
        $hourStart = $moment->utc()->startOfHour();

        $totals = DB::table('monitor_checks')
            ->where('monitor_id', $monitorId)
            ->where('checked_at', '>=', $hourStart)
            ->where('checked_at', '<', $hourStart->addHour())
            ->selectRaw(
                'count(*) as total_checks,
                sum(case when status = ? then 1 else 0 end) as down_checks,
                sum(case when status <> ? then response_time_ms else 0 end) as response_time_sum,
                sum(case when status <> ? and response_time_ms is not null then 1 else 0 end) as response_time_count,
                min(case when status <> ? then response_time_ms end) as response_time_min,
                max(case when status <> ? then response_time_ms end) as response_time_max',
                array_fill(0, 5, MonitorStatus::Down->value),
            )
            ->first();

        $this->store('monitor_hourly_stats', $monitorId, $hourStart->toDateTimeString(), $totals);
    }

    public function rebuildDay(int $monitorId, CarbonImmutable $moment): void
    {
        $dayStart = $moment->utc()->startOfDay();

        $totals = DB::table('monitor_hourly_stats')
            ->where('monitor_id', $monitorId)
            ->where('period_start', '>=', $dayStart)
            ->where('period_start', '<', $dayStart->addDay())
            ->selectRaw(
                'sum(total_checks) as total_checks, sum(down_checks) as down_checks,
                sum(response_time_sum) as response_time_sum, sum(response_time_count) as response_time_count,
                min(response_time_min) as response_time_min, max(response_time_max) as response_time_max',
            )
            ->first();

        $this->store('monitor_daily_stats', $monitorId, $dayStart->toDateString(), $totals);
    }

    private function store(string $table, int $monitorId, string $periodStart, ?object $totals): void
    {
        if ($totals === null || (int) $totals->total_checks === 0) {
            DB::table($table)->where('monitor_id', $monitorId)->where('period_start', $periodStart)->delete();

            return;
        }

        DB::table($table)->upsert([[
            'monitor_id' => $monitorId,
            'period_start' => $periodStart,
            'total_checks' => (int) $totals->total_checks,
            'down_checks' => (int) $totals->down_checks,
            'response_time_sum' => (int) $totals->response_time_sum,
            'response_time_count' => (int) $totals->response_time_count,
            'response_time_min' => $totals->response_time_min === null ? null : (int) $totals->response_time_min,
            'response_time_max' => $totals->response_time_max === null ? null : (int) $totals->response_time_max,
        ]], ['monitor_id', 'period_start'], [
            'total_checks', 'down_checks', 'response_time_sum', 'response_time_count', 'response_time_min', 'response_time_max',
        ]);
    }
}
