<?php

namespace App\Services\Monitoring;

use App\Enums\BackupRunStatus;
use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\VerifiedDomain;
use App\Models\Website;
use App\Models\Workspace;

/**
 * One row per domain of a workspace, joining its three records: ownership (verified_domains), backups
 * (the website) and uptime (the main monitor). Each table stays the single source of its own facts; this
 * only reads them side by side, in a handful of queries, and lists what needs the owner's attention.
 */
class DomainOverview
{
    public function __construct(private MonitorUptimeStatistics $statistics) {}

    /**
     * Rows needing attention first, then alphabetical.
     *
     * @return list<array{host: string, ownership: VerifiedDomain|null, website: Website|null, monitor: Monitor|null, uptime_percent: float|null, issues: list<string>}>
     */
    public function rows(Workspace $workspace): array
    {
        /** @var array<string, array{host: string, ownership: VerifiedDomain|null, website: Website|null, monitor: Monitor|null, uptime_percent: float|null, issues: list<string>}> $rows */
        $rows = [];
        $row = function (string $host) use (&$rows): string {
            $rows[$host] ??= ['host' => $host, 'ownership' => null, 'website' => null, 'monitor' => null, 'uptime_percent' => null, 'issues' => []];

            return $host;
        };

        foreach ($workspace->verifiedDomains()->get() as $verifiedDomain) {
            $rows[$row($verifiedDomain->domain)]['ownership'] = $verifiedDomain;
        }

        $websites = $workspace->websites()
            ->with(['verifiedDomain', 'latestBackupRun'])
            ->withCount(['backupPolicies as active_policies_count' => fn ($query) => $query->where('status', 'active')])
            ->get();

        foreach ($websites as $website) {
            if (blank($website->domain)) {
                continue;
            }

            $host = $row(VerifiedDomain::normalize((string) $website->domain));
            $rows[$host]['website'] = $website;
            $rows[$host]['ownership'] ??= $website->verifiedDomain;
        }

        $monitors = $workspace->monitors()->topLevel()->whereNotNull('target_host')->with('children')->get();
        $uptime = $this->statistics->hourlyBarsWithUptime($monitors->modelKeys());

        foreach ($monitors as $monitor) {
            $host = $row((string) $monitor->target_host);
            $rows[$host]['monitor'] = $monitor;
            $rows[$host]['uptime_percent'] = $uptime[$monitor->id]['uptime_percent'] ?? null;
        }

        foreach ($rows as $host => $domainRow) {
            $rows[$host]['issues'] = $this->issues($domainRow);
        }

        return collect($rows)
            ->sortBy([fn (array $a, array $b): int => ($b['issues'] !== []) <=> ($a['issues'] !== []), fn (array $a, array $b): int => $a['host'] <=> $b['host']])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{host: string, ownership: VerifiedDomain|null, website: Website|null, monitor: Monitor|null, uptime_percent: float|null, issues: list<string>}>  $rows
     * @return array{domains: int, verified: int, attention: int, down: int, backed_up: int}
     */
    public function summary(array $rows): array
    {
        $rows = collect($rows);

        return [
            'domains' => $rows->count(),
            'verified' => $rows->filter(fn (array $row): bool => (bool) $row['ownership']?->isVerified())->count(),
            'attention' => $rows->filter(fn (array $row): bool => $row['issues'] !== [])->count(),
            'down' => $rows->filter(fn (array $row): bool => $row['monitor']?->status === MonitorStatus::Down)->count(),
            'backed_up' => $rows->filter(fn (array $row): bool => $row['website'] !== null)->count(),
        ];
    }

    /**
     * @param  array{host: string, ownership: VerifiedDomain|null, website: Website|null, monitor: Monitor|null, uptime_percent: float|null, issues: list<string>}  $row
     * @return list<string>
     */
    private function issues(array $row): array
    {
        $ownership = $row['ownership'];
        $website = $row['website'];
        $monitor = $row['monitor'];
        $issues = [];

        if ($ownership?->isInGracePeriod()) {
            $issues[] = 'Verification token missing — '.$ownership->graceDaysLeft().' '.str('day')->plural((int) $ownership->graceDaysLeft()).' left';
        } elseif ($ownership?->ownership_lapsed_at !== null && ! $ownership->isVerified()) {
            $issues[] = 'Ownership lapsed — '.($website ? 'backups' : 'port checks').' paused';
        } elseif ($website !== null && ! $website->isVerified()) {
            $issues[] = 'Verify ownership to start backups';
        }

        if ($monitor?->status === MonitorStatus::Down) {
            $issues[] = 'Down'.($monitor->last_error ? ": {$monitor->last_error}" : '');
        }

        foreach ($monitor?->children ?? [] as $child) {
            if ($child->status === MonitorStatus::Down) {
                $issues[] = $child->type->label().' check failing';
            }
        }

        if ($website?->latestBackupRun?->status === BackupRunStatus::Failed) {
            $issues[] = 'Last backup failed';
        }

        if ($website !== null && $monitor === null && $website->uptime_monitoring_enabled) {
            $issues[] = 'No uptime monitor';
        }

        return $issues;
    }
}
