<?php

namespace App\Console\Commands;

use App\Enums\WebsiteVerificationStatus;
use App\Models\Website;
use App\Services\Verification\WebsiteUptimeMonitorService;
use Illuminate\Console\Command;

class CheckWebsiteUptimeCommand extends Command
{
    protected $signature = 'sitevaulta:check-websites-uptime';

    protected $description = 'Refresh server-connection and SSL health for verified websites. HTTP uptime of websites with a linked monitor is handled by sitevaulta:check-monitors; websites without one (e.g. monitor quota full) still get the legacy full check.';

    public function handle(): int
    {
        $websites = Website::with(['workspace.owner', 'serverConnections'])
            ->withExists('monitors')
            ->where('verification_status', WebsiteVerificationStatus::Verified)
            ->where('status', 'active')
            ->where('uptime_monitoring_enabled', true)
            ->get();

        $healthOnlyCount = 0;
        $legacyCount = 0;

        foreach ($websites as $website) {
            if ($website->monitors_exists) {
                WebsiteUptimeMonitorService::refreshServerAndSslHealth($website);
                $healthOnlyCount++;

                continue;
            }

            WebsiteUptimeMonitorService::checkWebsiteLiveStatus($website);
            $legacyCount++;
        }

        $this->info("Checked {$websites->count()} verified websites | Server & SSL only (monitor-linked): {$healthOnlyCount} | Legacy full check: {$legacyCount}");

        return self::SUCCESS;
    }
}
