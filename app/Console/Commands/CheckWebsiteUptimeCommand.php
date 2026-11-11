<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Monitoring\WebsiteMonitorSync;
use App\Services\Verification\WebsiteUptimeMonitorService;
use Illuminate\Console\Command;

class CheckWebsiteUptimeCommand extends Command
{
    protected $signature = 'sitevaulta:check-websites-uptime';

    protected $description = 'Refresh server-connection and SSL health for verified websites and give any website without one an uptime monitor. HTTP uptime itself is checked by sitevaulta:check-monitors.';

    public function handle(): int
    {
        $websites = Website::with(['workspace.owner', 'serverConnections', 'uptimeMonitor'])
            ->ownershipVerified()
            ->where('status', 'active')
            ->where('uptime_monitoring_enabled', true)
            ->get();

        $sync = app(WebsiteMonitorSync::class);
        $newMonitors = 0;

        // HTTP uptime is checked by each website's monitor (sitevaulta:check-monitors); this command only
        // refreshes what belongs to the website itself: server connections and the SSL certificate.
        foreach ($websites as $website) {
            if ($website->uptimeMonitor === null && $sync->ensureMonitorFor($website) !== null) {
                $newMonitors++;
                $website->unsetRelation('uptimeMonitor');
            }

            WebsiteUptimeMonitorService::refreshServerAndSslHealth($website);
        }

        $this->info("Refreshed server & SSL health of {$websites->count()} verified websites | New uptime monitors: {$newMonitors}");

        return self::SUCCESS;
    }
}
