<?php

namespace App\Services\Monitoring;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\Website;
use App\Services\Billing\EntitlementService;

/**
 * Keeps every backup Website paired with one HTTP(s) Monitor. The monitor owns the website's uptime;
 * the website reads it through accessors (Website::uptime_status etc.) and keeps no copy.
 */
class WebsiteMonitorSync
{
    /**
     * Creates the website's HTTP monitor when monitoring is enabled and the plan has a free slot.
     */
    public function ensureMonitorFor(Website $website): ?Monitor
    {
        $existing = $website->monitors()->first();

        if ($existing !== null || ! ($website->uptime_monitoring_enabled ?? true)) {
            return $existing;
        }

        $workspace = $website->workspace;

        if ($workspace === null) {
            return null;
        }

        // The domain is already monitored (e.g. added by hand): link that monitor instead of duplicating it.
        $sameHost = $workspace->monitors()
            ->where('target_host', Monitor::normalizeHost(self::monitorUrlFor($website)))
            ->first();

        if ($sameHost !== null) {
            if ($sameHost->website_id === null) {
                $sameHost->update(['website_id' => $website->id]);
            }

            return $sameHost;
        }

        if (! EntitlementService::canAddMonitor($workspace)) {
            return null;
        }

        return $workspace->monitors()->create([
            'website_id' => $website->id,
            'name' => $website->name ?: $website->domain,
            'url' => self::monitorUrlFor($website),
            'interval_seconds' => max(300, EntitlementService::minMonitorIntervalSeconds($workspace)),
        ]);
    }

    /**
     * Mirrors website edits onto its linked monitor: URL changes and the monitoring on/off switch.
     */
    public function syncFromWebsite(Website $website): void
    {
        if (! $website->wasChanged(['url', 'domain', 'uptime_monitoring_enabled'])) {
            return;
        }

        $monitor = $website->monitors()->first();

        // (A monitor skipped earlier is also created when the domain becomes verified — see VerifiedDomain.)
        if ($monitor === null) {
            if ($website->wasChanged('uptime_monitoring_enabled') && $website->uptime_monitoring_enabled) {
                $this->ensureMonitorFor($website);
            }

            return;
        }

        $changes = [];

        if ($website->wasChanged(['url', 'domain'])) {
            $changes['url'] = self::monitorUrlFor($website);
        }

        if ($website->wasChanged('uptime_monitoring_enabled')) {
            $changes['status'] = $website->uptime_monitoring_enabled ? MonitorStatus::Pending : MonitorStatus::Paused;
            $changes['last_checked_at'] = null;
        }

        if ($changes !== []) {
            $monitor->update($changes);
        }
    }

    public static function monitorUrlFor(Website $website): string
    {
        return str_contains((string) $website->url, '://')
            ? (string) $website->url
            : "https://{$website->domain}/";
    }
}
