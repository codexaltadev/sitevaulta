<?php

namespace App\Console\Commands;

use App\Enums\WebsiteVerificationStatus;
use App\Models\Website;
use App\Services\Verification\WebsiteUptimeMonitorService;
use Illuminate\Console\Command;

class CheckWebsiteUptimeCommand extends Command
{
    protected $signature = 'sitevaulta:check-websites-uptime';

    protected $description = 'Check live HTTP/HTTPS and server reachability for all verified websites and alert owners on downtime or recovery.';

    public function handle(): int
    {
        $websites = Website::with(['workspace.owner', 'serverConnections'])
            ->where('verification_status', WebsiteVerificationStatus::Verified)
            ->where('status', 'active')
            ->where('uptime_monitoring_enabled', true)
            ->get();

        $onlineCount = 0;
        $downCount = 0;

        foreach ($websites as $website) {
            $result = WebsiteUptimeMonitorService::checkWebsiteLiveStatus($website);
            if ($result['is_online']) {
                $onlineCount++;
            } else {
                $downCount++;
            }
        }

        $this->info("Checked {$websites->count()} verified websites | Online: {$onlineCount} | Down: {$downCount}");

        return self::SUCCESS;
    }
}

