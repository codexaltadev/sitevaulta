<?php

namespace App\Http\Controllers;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorCheckRunner;
use Illuminate\Http\Response;

/**
 * Public endpoint a cron job calls when it finishes: GET/POST/HEAD /heartbeat/{token}.
 * The unguessable 40-character token is the only credential, so the response never reveals monitor details.
 */
class HeartbeatController extends Controller
{
    public function __invoke(string $token, MonitorCheckRunner $runner): Response
    {
        $monitor = Monitor::query()
            ->where('type', MonitorType::Heartbeat)
            ->where('heartbeat_token', $token)
            ->with(['workspace', 'maintenanceWindows'])
            ->firstOrFail();

        if ($monitor->status === MonitorStatus::Paused) {
            return response('OK (monitor paused)', 200)->header('Content-Type', 'text/plain');
        }

        $runner->recordHeartbeat($monitor);

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }
}
