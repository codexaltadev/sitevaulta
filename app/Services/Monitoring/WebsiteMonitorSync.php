<?php

namespace App\Services\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\WebsiteVerificationStatus;
use App\Models\Monitor;
use App\Models\Website;
use App\Services\Billing\EntitlementService;

/**
 * Keeps every backup Website paired with one HTTP(s) Monitor, so the standalone monitoring engine
 * owns website uptime while the legacy website columns (Websites table, client PDF reports) stay filled.
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
        if (! $website->wasChanged(['url', 'domain', 'uptime_monitoring_enabled', 'verification_status'])) {
            return;
        }

        $monitor = $website->monitors()->first();

        if ($monitor === null) {
            $justVerified = $website->wasChanged('verification_status')
                && $website->verification_status === WebsiteVerificationStatus::Verified;

            if ($website->uptime_monitoring_enabled && ($justVerified || $website->wasChanged('uptime_monitoring_enabled'))) {
                $this->ensureMonitorFor($website);
            }

            return;
        }

        if (! $website->wasChanged(['url', 'domain', 'uptime_monitoring_enabled'])) {
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

    /**
     * Copies the monitor's latest HTTP result into the website's legacy uptime columns.
     */
    public function pushResultToWebsite(Monitor $monitor): void
    {
        $website = $monitor->website;

        if ($website === null) {
            return;
        }

        $website->forceFill([
            'uptime_status' => match ($monitor->status) {
                MonitorStatus::Up => 'online',
                MonitorStatus::Down => 'down',
                default => $website->uptime_status,
            },
            'last_http_status_code' => $monitor->last_http_status_code,
            'last_response_time_ms' => $monitor->last_response_time_ms,
            'last_uptime_checked_at' => $monitor->last_checked_at,
            'last_down_reason' => $monitor->status === MonitorStatus::Down ? $monitor->last_error : null,
        ])->saveQuietly();
    }

    public static function monitorUrlFor(Website $website): string
    {
        return str_contains((string) $website->url, '://')
            ? (string) $website->url
            : "https://{$website->domain}/";
    }
}
