<?php

namespace App\Services\Monitoring;

use App\Models\ProbeLocation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * How our checks identify themselves to the sites they monitor: a descriptive User-Agent that links to
 * the public allowlist page, and the probe IP addresses listed on that page.
 */
class MonitoringIdentity
{
    public function userAgent(): string
    {
        return config('monitoring.user_agent_name').' (+'.route('monitoring.ips').')';
    }

    /**
     * Every IP our checks can come from: this server and every enabled probe location.
     *
     * @return list<string>
     */
    public function probeIps(): array
    {
        $locationIps = ProbeLocation::query()->where('is_enabled', true)->pluck('public_ips')->flatten()->filter()->all();

        return array_values(array_unique([...$this->mainServerIps(), ...$locationIps]));
    }

    /**
     * @return list<string>
     */
    private function mainServerIps(): array
    {
        $configured = (array) config('monitoring.probe_ips', []);

        if ($configured !== [] || ! config('monitoring.detect_public_ip')) {
            return array_values(array_filter($configured, fn (string $ip): bool => DestinationGuard::isPublicIp($ip)));
        }

        $cached = Cache::get('monitoring.detected_public_ips');

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $ip = trim(Http::connectTimeout(3)->timeout(5)->get('https://api.ipify.org')->throw()->body());
        } catch (Throwable) {
            return [];
        }

        if (! DestinationGuard::isPublicIp($ip)) {
            return [];
        }

        // Only a successful detection is cached, so a temporary failure is retried on the next view.
        Cache::put('monitoring.detected_public_ips', [$ip], now()->addHours(6));

        return [$ip];
    }
}
