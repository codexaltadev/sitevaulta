<?php

namespace App\Console\Commands;

use App\Models\ProbeLocation;
use App\Models\User;
use App\Services\Monitoring\RemoteProbeClient;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

class CheckProbeLocationsCommand extends Command
{
    protected $signature = 'sitevaulta:check-probe-locations';

    protected $description = 'Ping every enabled probe agent; an agent that does not answer is left out of check votes and admins are told.';

    public function handle(RemoteProbeClient $client): int
    {
        $healthy = 0;
        $locations = ProbeLocation::query()->where('is_enabled', true)->get();

        foreach ($locations as $location) {
            $wasHealthy = $location->health_status !== ProbeLocation::UNREACHABLE;
            $isHealthy = $client->checkHealth($location);
            $healthy += (int) $isHealthy;

            if ($wasHealthy !== $isHealthy) {
                $this->notifyAdmins($location, $isHealthy);
            }
        }

        $this->info("Probe locations healthy: {$healthy} of {$locations->count()}");

        return self::SUCCESS;
    }

    private function notifyAdmins(ProbeLocation $location, bool $isHealthy): void
    {
        $admins = User::query()->where('can_access_admin_panel', true)->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::make()
            ->title($isHealthy ? "Probe location {$location->name} is back" : "Probe location {$location->name} is not answering")
            ->body($isHealthy
                ? 'It takes part in confirming outages again.'
                : 'Outages are confirmed by the remaining locations until it answers again. '.($location->last_error ?? ''))
            ->status($isHealthy ? 'success' : 'warning')
            ->sendToDatabase($admins);
    }
}
