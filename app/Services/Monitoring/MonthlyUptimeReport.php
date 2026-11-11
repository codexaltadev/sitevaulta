<?php

namespace App\Services\Monitoring;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind the monthly uptime report: per main monitor the month's uptime %, incidents,
 * downtime, longest outage and average response time, plus extra-check expiry dates. Months are UTC
 * calendar months, matching the daily totals they are read from.
 */
class MonthlyUptimeReport
{
    /**
     * @return array{
     *     workspace: Workspace,
     *     month: CarbonImmutable,
     *     monitors: list<array{monitor: Monitor, uptime_percent: float|null, checks: int, incidents: int, downtime_seconds: int, longest_outage_seconds: int, average_response_ms: int|null, expiries: list<array{label: string, date: string|null}>}>,
     *     overall_uptime_percent: float|null,
     *     total_incidents: int,
     *     total_downtime_seconds: int
     * }
     */
    public function build(Workspace $workspace, CarbonImmutable $month): array
    {
        $start = $month->utc()->startOfMonth();
        $end = $start->endOfMonth();

        $monitors = $workspace->monitors()
            ->topLevel()
            ->where('type', '!=', MonitorType::Heartbeat)
            ->where('created_at', '<=', $end)
            ->with('children')
            ->orderBy('name')
            ->get();

        $totals = DB::table('monitor_daily_stats')
            ->whereIn('monitor_id', $monitors->modelKeys())
            ->whereBetween('period_start', [$start->toDateString(), $end->toDateString()])
            ->groupBy('monitor_id')
            ->selectRaw('monitor_id, sum(total_checks) as total_checks, sum(down_checks) as down_checks, sum(response_time_sum) as response_time_sum, sum(response_time_count) as response_time_count')
            ->get()
            ->keyBy('monitor_id');

        $incidents = MonitorIncident::query()
            ->whereIn('monitor_id', $monitors->modelKeys())
            ->where('started_at', '<=', $end)
            ->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $start))
            ->get(['monitor_id', 'started_at', 'resolved_at'])
            ->groupBy('monitor_id');

        $rows = [];
        $allChecks = 0;
        $allDown = 0;

        foreach ($monitors as $monitor) {
            $total = $totals[$monitor->id] ?? null;
            $checks = (int) ($total->total_checks ?? 0);
            $down = (int) ($total->down_checks ?? 0);
            $allChecks += $checks;
            $allDown += $down;

            // Outages are clipped to [first second of the month, first second of the next month).
            $nextMonth = $start->addMonthNoOverflow();
            $outages = ($incidents[$monitor->id] ?? collect())->map(function (MonitorIncident $incident) use ($start, $nextMonth): int {
                $from = CarbonImmutable::instance($incident->started_at)->max($start);
                $to = CarbonImmutable::instance($incident->resolved_at ?? now())->min($nextMonth)->min(CarbonImmutable::now('UTC'));

                return max(0, (int) $from->diffInSeconds($to, false));
            });

            $rows[] = [
                'monitor' => $monitor,
                'uptime_percent' => $checks > 0 ? round(($checks - $down) / $checks * 100, 3) : null,
                'checks' => $checks,
                'incidents' => $outages->count(),
                'downtime_seconds' => (int) $outages->sum(),
                'longest_outage_seconds' => (int) ($outages->max() ?? 0),
                'average_response_ms' => (int) ($total->response_time_count ?? 0) > 0 ? (int) round($total->response_time_sum / $total->response_time_count) : null,
                'expiries' => $monitor->children
                    ->filter(fn (Monitor $child): bool => $child->type->isExpiryCheck())
                    ->map(fn (Monitor $child): array => ['label' => $child->type->label(), 'date' => $child->expiryDateLabel()])
                    ->values()
                    ->all(),
            ];
        }

        return [
            'workspace' => $workspace,
            'month' => $start,
            'monitors' => $rows,
            'overall_uptime_percent' => $allChecks > 0 ? round(($allChecks - $allDown) / $allChecks * 100, 3) : null,
            'total_incidents' => array_sum(array_column($rows, 'incidents')),
            'total_downtime_seconds' => array_sum(array_column($rows, 'downtime_seconds')),
        ];
    }
}
