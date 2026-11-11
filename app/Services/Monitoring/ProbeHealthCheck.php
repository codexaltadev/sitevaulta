<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;

/**
 * Tells "the site is down" apart from "our probe lost its own DNS / internet". Before a DNS, connection or
 * timeout failure becomes an incident, the probe checks that it can still resolve and reach well-known
 * hosts. The answer is cached briefly, so a burst of simultaneous failures costs one self-check.
 */
class ProbeHealthCheck
{
    /**
     * Root causes that our own network trouble can produce. HTTP 5xx, keyword, SSL expiry etc. prove the
     * probe reached the site, so they never need this check.
     *
     * @var list<string>
     */
    public const NETWORK_ROOT_CAUSES = ['dns', 'connection', 'timeout', 'ping', 'port'];

    private const CACHE_KEY = 'monitoring:probe-network-healthy';

    private const CACHE_SECONDS = 60;

    public function __construct(private NetworkProbe $networkProbe) {}

    public function isHealthy(): bool
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached !== null) {
            return (bool) $cached;
        }

        $isHealthy = $this->probeControlHosts();
        // A failing network is re-tested sooner, so checks resume as soon as it is back.
        Cache::put(self::CACHE_KEY, $isHealthy, $isHealthy ? self::CACHE_SECONDS : 20);

        return $isHealthy;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Healthy when at least one control host both resolves and accepts a TCP connection on 443.
     */
    private function probeControlHosts(): bool
    {
        $controlHosts = (array) config('monitoring.control_hosts', []);

        // Self-check switched off: trust every failure as before.
        if ($controlHosts === []) {
            return true;
        }

        foreach ($controlHosts as $host) {
            $ips = $this->networkProbe->resolveIps((string) $host);

            if ($ips !== [] && $this->networkProbe->tcpConnect($ips[0], 443, 4)['connected']) {
                return true;
            }
        }

        return false;
    }
}
