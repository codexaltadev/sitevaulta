<?php

namespace App\Services\Monitoring;

use App\Enums\MonitorIncidentStatus;
use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The numbers shown on the monitors list, detail page and status pages: hourly bars + 24h uptime %,
 * daily bars, period summaries and response-time series. Uptime counts come from the hourly/daily
 * stats tables (see MonitorStatsRecorder); only the 1h/24h response chart reads raw checks.
 */
class MonitorUptimeStatistics
{
    public const WINDOW_HOURS = 24;

    /**
     * @param  list<int>  $monitorIds
     *                                 Keyed by monitor id, oldest hour first. A bar is "up", "down" or "none"; `hours` says how much of that
     *                                 hour was really down (from incident start/end times), so a 5-minute blip is drawn as a sliver, not a full red bar.
     * @return array<int, array{bars: list<string>, hours: list<array{down_seconds: int, up_percent: float|null, segments: list<array{int, int}>, elapsed_seconds: int}>, uptime_percent: float|null}>
     */
    public function hourlyBarsWithUptime(array $monitorIds): array
    {
        $hourKeys = $this->hourKeys();
        $results = [];

        foreach ($monitorIds as $monitorId) {
            $results[$monitorId] = [
                'bars' => array_fill(0, self::WINDOW_HOURS, 'none'),
                'hours' => array_fill(0, self::WINDOW_HOURS, ['down_seconds' => 0, 'up_percent' => null, 'segments' => [], 'elapsed_seconds' => 3600]),
                'uptime_percent' => null,
            ];
        }

        if ($monitorIds === []) {
            return $results;
        }

        $rows = DB::table('monitor_hourly_stats')
            ->whereIn('monitor_id', $monitorIds)
            ->where('period_start', '>=', $this->windowStart())
            ->get(['monitor_id', 'period_start', 'total_checks', 'down_checks']);

        $totals = [];

        foreach ($rows as $row) {
            $monitorId = (int) $row->monitor_id;
            $position = $hourKeys[CarbonImmutable::parse($row->period_start, 'UTC')->format('Y-m-d H')] ?? null;

            if ($position !== null) {
                $results[$monitorId]['bars'][$position] = (int) $row->down_checks > 0 ? 'down' : 'up';
            }

            $totals[$monitorId]['total'] = ($totals[$monitorId]['total'] ?? 0) + (int) $row->total_checks;
            $totals[$monitorId]['down'] = ($totals[$monitorId]['down'] ?? 0) + (int) $row->down_checks;
        }

        foreach ($totals as $monitorId => $total) {
            $results[$monitorId]['uptime_percent'] = round(($total['total'] - $total['down']) / $total['total'] * 100, 3);
        }

        $this->applyHourlyDowntime($results, $monitorIds);

        return $results;
    }

    /**
     * Splits each incident's start–end span across the hourly bars it touches, so every bar knows how many
     * seconds of that hour were really down.
     *
     * @param  array<int, array{bars: list<string>, hours: list<array{down_seconds: int, up_percent: float|null, segments: list<array{int, int}>, elapsed_seconds: int}>, uptime_percent: float|null}>  $results
     * @param  list<int>  $monitorIds
     */
    private function applyHourlyDowntime(array &$results, array $monitorIds): void
    {
        $windowStart = $this->windowStart();
        $now = CarbonImmutable::now('UTC');

        $incidents = MonitorIncident::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where('started_at', '<', $now)
            ->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>', $windowStart))
            ->get(['monitor_id', 'started_at', 'resolved_at']);

        $segments = [];

        foreach ($incidents as $incident) {
            $from = CarbonImmutable::instance($incident->started_at)->max($windowStart);
            $to = CarbonImmutable::instance($incident->resolved_at ?? $now)->min($now);

            for ($position = 0; $position < self::WINDOW_HOURS; $position++) {
                $hourStart = $windowStart->addHours($position);
                $segmentStart = (int) $hourStart->diffInSeconds($from->max($hourStart), false);
                $segmentEnd = (int) $hourStart->diffInSeconds($to->min($hourStart->addHour()), false);

                if ($segmentEnd > $segmentStart) {
                    $segments[$incident->monitor_id][$position][] = [$segmentStart, $segmentEnd];
                    $results[$incident->monitor_id]['bars'][$position] = 'down';
                }
            }
        }

        foreach ($results as $monitorId => $result) {
            foreach (array_keys($result['hours']) as $position) {
                $hourStart = $windowStart->addHours($position);
                $elapsedSeconds = max(1, (int) $hourStart->diffInSeconds($hourStart->addHour()->min($now)));
                $merged = $this->mergeSegments($segments[$monitorId][$position] ?? []);
                $downSeconds = min($elapsedSeconds, array_sum(array_map(fn (array $segment): int => $segment[1] - $segment[0], $merged)));

                $results[$monitorId]['hours'][$position] = [
                    'down_seconds' => $downSeconds,
                    'up_percent' => $result['bars'][$position] === 'none'
                        ? null
                        : round(($elapsedSeconds - $downSeconds) / $elapsedSeconds * 100, 1),
                    'segments' => $merged,
                    'elapsed_seconds' => $elapsedSeconds,
                ];
            }
        }
    }

    /**
     * Sorts and joins overlapping [start, end] second offsets, so two overlapping incidents count once.
     *
     * @param  list<array{int, int}>  $segments
     * @return list<array{int, int}>
     */
    private function mergeSegments(array $segments): array
    {
        usort($segments, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];

        foreach ($segments as $segment) {
            $last = array_key_last($merged);

            if ($last !== null && $segment[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $segment[1]);

                continue;
            }

            $merged[] = $segment;
        }

        return $merged;
    }

    /**
     * One bar per UTC day for the public status page (oldest first).
     *
     * @param  list<int>  $monitorIds
     * @return array<int, array{days: list<array{date: string, state: string, uptime_percent: float|null}>, uptime_percent: float|null}>
     */
    public function dailyBarsWithUptime(array $monitorIds, int $days = 90): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $firstDay = $today->subDays($days - 1);
        $results = [];

        foreach ($monitorIds as $monitorId) {
            $results[$monitorId] = ['days' => [], 'uptime_percent' => null];
        }

        if ($monitorIds === []) {
            return $results;
        }

        $rows = DB::table('monitor_daily_stats')
            ->whereIn('monitor_id', $monitorIds)
            ->where('period_start', '>=', $firstDay->toDateString())
            ->get(['monitor_id', 'period_start', 'total_checks', 'down_checks'])
            ->map(function (object $row): object {
                $row->day_bucket = substr((string) $row->period_start, 0, 10);

                return $row;
            })
            ->groupBy('monitor_id');

        foreach ($monitorIds as $monitorId) {
            $byDay = ($rows[$monitorId] ?? collect())->keyBy('day_bucket');
            $total = 0;
            $down = 0;

            for ($offset = 0; $offset < $days; $offset++) {
                $date = $firstDay->addDays($offset)->format('Y-m-d');
                $row = $byDay[$date] ?? null;

                if ($row === null) {
                    $results[$monitorId]['days'][] = ['date' => $date, 'state' => 'none', 'uptime_percent' => null];

                    continue;
                }

                $dayTotal = (int) $row->total_checks;
                $dayDown = (int) $row->down_checks;
                $total += $dayTotal;
                $down += $dayDown;
                $dayPercent = round(($dayTotal - $dayDown) / $dayTotal * 100, 2);

                $results[$monitorId]['days'][] = [
                    'date' => $date,
                    'state' => match (true) {
                        $dayDown === 0 => 'up',
                        $dayPercent >= 99 => 'degraded',
                        default => 'down',
                    },
                    'uptime_percent' => $dayPercent,
                ];
            }

            $results[$monitorId]['uptime_percent'] = $total > 0 ? round(($total - $down) / $total * 100, 3) : null;
        }

        return $results;
    }

    /**
     * @return array{down: int, up: int, paused: int, pending: int, total: int, uptime_percent: float|null, incidents: int, downtime_seconds: int, mtbf_seconds: int|null}
     */
    public function workspaceSummary(Workspace $workspace): array
    {
        $statusCounts = $workspace->monitors()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->toBase()
            ->pluck('aggregate', 'status');

        $windowStart = $this->windowStart();
        $now = CarbonImmutable::now('UTC');

        $checkTotals = DB::table('monitor_hourly_stats')
            ->whereIn('monitor_id', $workspace->monitors()->select('id'))
            ->where('period_start', '>=', $windowStart)
            ->selectRaw('sum(total_checks) as total_checks, sum(down_checks) as down_checks')
            ->first();

        $totalChecks = (int) ($checkTotals->total_checks ?? 0);

        $incidents = MonitorIncident::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($windowStart): void {
                $query->where('status', MonitorIncidentStatus::Ongoing)
                    ->orWhere('resolved_at', '>=', $windowStart)
                    ->orWhere('started_at', '>=', $windowStart);
            })
            ->get(['started_at', 'resolved_at']);

        $downtimeSeconds = $this->downtimeWithin($incidents, $windowStart, $now);

        $activeMonitorCount = (int) $statusCounts->except(MonitorStatus::Paused->value)->sum();
        $monitoredSeconds = $activeMonitorCount * self::WINDOW_HOURS * 3600;

        return [
            'down' => (int) ($statusCounts[MonitorStatus::Down->value] ?? 0),
            'up' => (int) ($statusCounts[MonitorStatus::Up->value] ?? 0),
            'paused' => (int) ($statusCounts[MonitorStatus::Paused->value] ?? 0),
            'pending' => (int) ($statusCounts[MonitorStatus::Pending->value] ?? 0),
            'total' => (int) $statusCounts->sum(),
            'uptime_percent' => $totalChecks > 0
                ? round(($totalChecks - (int) $checkTotals->down_checks) / $totalChecks * 100, 3)
                : null,
            'incidents' => $incidents->count(),
            'downtime_seconds' => $downtimeSeconds,
            'mtbf_seconds' => $incidents->isNotEmpty()
                ? intdiv(max(0, $monitoredSeconds - $downtimeSeconds), $incidents->count())
                : null,
        ];
    }

    /**
     * Uptime %, incident count, downtime and MTBF for one monitor over the last N days.
     *
     * @return array{uptime_percent: float|null, incidents: int, downtime_seconds: int, mtbf_seconds: int|null}
     */
    public function monitorPeriodSummary(Monitor $monitor, int $days): array
    {
        $now = CarbonImmutable::now('UTC');
        $periodStart = $now->subDays($days);

        // Hourly totals while they are kept; whole days beyond that (e.g. the 365-day figure).
        $hourlyDays = $monitor->workspace !== null
            ? EntitlementService::monitorRetention($monitor->workspace)['hourly_days']
            : (int) config('monitoring.retention.hourly_days', 90);
        $useHourly = $days <= $hourlyDays;

        $checkTotals = DB::table($useHourly ? 'monitor_hourly_stats' : 'monitor_daily_stats')
            ->where('monitor_id', $monitor->id)
            ->where('period_start', '>=', $useHourly ? $periodStart->startOfHour() : $periodStart->toDateString())
            ->selectRaw('sum(total_checks) as total_checks, sum(down_checks) as down_checks')
            ->first();

        $totalChecks = (int) ($checkTotals->total_checks ?? 0);

        $incidents = $this->incidentsOverlapping($monitor, $periodStart);
        $downtimeSeconds = $this->downtimeWithin($incidents, $periodStart, $now);
        $monitoredSeconds = (int) $periodStart->max($monitor->created_at)->diffInSeconds($now);

        return [
            'uptime_percent' => $totalChecks > 0
                ? round(($totalChecks - (int) $checkTotals->down_checks) / $totalChecks * 100, 3)
                : null,
            'incidents' => $incidents->count(),
            'downtime_seconds' => $downtimeSeconds,
            'mtbf_seconds' => $incidents->isNotEmpty()
                ? intdiv(max(0, $monitoredSeconds - $downtimeSeconds), $incidents->count())
                : null,
        ];
    }

    /**
     * Response-time series for the chart. "1h" and "24h" use raw checks; "7d" averages per hour.
     * A null "ms" marks a failed check (a gap in the line).
     *
     * @return array{points: list<array{at: int, ms: int|null}>, downtime: list<array{from: int, to: int}>, average_ms: int|null, minimum_ms: int|null, maximum_ms: int|null, from: int, to: int}
     */
    public function responseTimeSeries(Monitor $monitor, string $range): array
    {
        $now = CarbonImmutable::now('UTC');
        $rangeStart = match ($range) {
            '1h' => $now->subHour(),
            '7d' => $now->subDays(7),
            default => $now->subDay(),
        };

        if ($range === '7d') {
            // Hourly averages of successful checks; an hour with only failures is a gap.
            $points = DB::table('monitor_hourly_stats')
                ->where('monitor_id', $monitor->id)
                ->where('period_start', '>=', $rangeStart->startOfHour())
                ->orderBy('period_start')
                ->get(['period_start', 'response_time_sum', 'response_time_count'])
                ->map(fn (object $row): array => [
                    'at' => CarbonImmutable::parse($row->period_start, 'UTC')->getTimestamp(),
                    'ms' => (int) $row->response_time_count > 0 ? (int) round($row->response_time_sum / $row->response_time_count) : null,
                ]);
        } else {
            $points = $monitor->checks()
                ->where('checked_at', '>=', $rangeStart)
                ->orderBy('checked_at')
                ->toBase()
                ->get(['checked_at', 'response_time_ms', 'status'])
                // Successful checks without a timing (e.g. heartbeats) are not drawn rather than shown as gaps.
                ->reject(fn (object $row): bool => $row->status !== MonitorStatus::Down->value && $row->response_time_ms === null)
                ->map(fn (object $row): array => [
                    'at' => CarbonImmutable::parse($row->checked_at, 'UTC')->getTimestamp(),
                    'ms' => $row->status === MonitorStatus::Down->value || $row->response_time_ms === null ? null : (int) $row->response_time_ms,
                ]);
        }

        $measured = $points->pluck('ms')->filter(fn (?int $ms): bool => $ms !== null);

        $downtime = $this->incidentsOverlapping($monitor, $rangeStart)
            ->map(fn (MonitorIncident $incident): array => [
                'from' => $incident->started_at->max($rangeStart)->getTimestamp(),
                'to' => ($incident->resolved_at ?? $now)->getTimestamp(),
            ])
            ->values()
            ->all();

        return [
            'points' => $points->values()->all(),
            'downtime' => $downtime,
            'average_ms' => $measured->isNotEmpty() ? (int) round($measured->avg()) : null,
            'minimum_ms' => $measured->min(),
            'maximum_ms' => $measured->max(),
            'from' => $rangeStart->getTimestamp(),
            'to' => $now->getTimestamp(),
        ];
    }

    /**
     * @return Collection<int, MonitorIncident>
     */
    private function incidentsOverlapping(Monitor $monitor, CarbonImmutable $periodStart): Collection
    {
        return $monitor->incidents()
            ->where(function ($query) use ($periodStart): void {
                $query->where('status', MonitorIncidentStatus::Ongoing)
                    ->orWhere('resolved_at', '>=', $periodStart)
                    ->orWhere('started_at', '>=', $periodStart);
            })
            ->get(['id', 'started_at', 'resolved_at']);
    }

    /**
     * @param  iterable<MonitorIncident>  $incidents
     */
    private function downtimeWithin(iterable $incidents, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): int
    {
        $total = 0;

        foreach ($incidents as $incident) {
            $start = $incident->started_at->max($periodStart);
            $end = $incident->resolved_at ?? $periodEnd;
            $total += max(0, (int) $start->diffInSeconds($end));
        }

        return $total;
    }

    private function windowStart(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfHour()->subHours(self::WINDOW_HOURS - 1);
    }

    /**
     * @return array<string, int> "Y-m-d H" bucket key => bar position (0 = oldest hour)
     */
    private function hourKeys(): array
    {
        $start = $this->windowStart();
        $keys = [];

        for ($position = 0; $position < self::WINDOW_HOURS; $position++) {
            $keys[$start->addHours($position)->format('Y-m-d H')] = $position;
        }

        return $keys;
    }
}
