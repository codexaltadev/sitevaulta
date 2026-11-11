<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Monitoring\WebsiteMonitorSync;
use Illuminate\Console\Command;

class SyncWebsiteMonitorsCommand extends Command
{
    protected $signature = 'sitevaulta:sync-website-monitors';

    protected $description = 'Create the linked HTTP(s) monitor for every website that has uptime monitoring enabled but no monitor yet (respects plan monitor quotas).';

    public function handle(WebsiteMonitorSync $sync): int
    {
        $createdCount = 0;
        $skippedCount = 0;

        Website::query()
            ->with('workspace')
            ->where('uptime_monitoring_enabled', true)
            ->whereDoesntHave('monitors')
            ->lazyById(100)
            ->each(function (Website $website) use ($sync, &$createdCount, &$skippedCount): void {
                $sync->ensureMonitorFor($website) !== null ? $createdCount++ : $skippedCount++;
            });

        $this->info("Created {$createdCount} website monitors | Skipped (quota full or no active plan): {$skippedCount}");

        return self::SUCCESS;
    }
}
