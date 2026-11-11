<?php

namespace App\Services\Monitoring;

/**
 * Keeps monitors from reaching our own infrastructure (SSRF / internal port scanning).
 *
 * A destination is allowed only when every address it resolves to is a public, globally routable IP —
 * no loopback, private (10/8, 172.16/12, 192.168/16), link-local / cloud metadata (169.254/16),
 * CGNAT, reserved or IPv4-mapped ranges. Checks connect to the exact IP validated here, so the DNS
 * answer cannot be swapped between validation and connection (DNS rebinding).
 */
class DestinationGuard
{
    /**
     * @var list<string>
     */
    private const BLOCKED_HOST_SUFFIXES = ['localhost', '.localhost', '.local', '.internal', '.localdomain', '.home.arpa'];

    public function __construct(private NetworkProbe $networkProbe) {}

    public static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /**
     * @return array{allowed: bool, ips: list<string>, reason: string|null}
     */
    public function inspectHost(string $host): array
    {
        $host = mb_strtolower(trim($host, " \t\n\r\0\x0B[]."));

        if ($host === '') {
            return $this->deny('No host given');
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                return $this->deny("{$host} is a local/internal name and cannot be monitored");
            }
        }

        $ips = $this->networkProbe->resolveIps($host);

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return $this->deny("{$host} resolves to a private or reserved address ({$ip}); only public addresses can be monitored", $ips);
            }
        }

        // An unresolvable host is allowed through so the check itself reports the DNS problem.
        return ['allowed' => true, 'ips' => $ips, 'reason' => null];
    }

    /**
     * @return array{allowed: bool, ips: list<string>, reason: string|null, host: string|null, port: int|null}
     */
    public function inspectUrl(string $url): array
    {
        $parts = parse_url($url);
        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return [...$this->deny('Only http:// and https:// URLs can be monitored'), 'host' => null, 'port' => null];
        }

        return [
            ...$this->inspectHost($host),
            'host' => $host,
            'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
        ];
    }

    /**
     * @param  list<string>  $ips
     * @return array{allowed: bool, ips: list<string>, reason: string}
     */
    private function deny(string $reason, array $ips = []): array
    {
        return ['allowed' => false, 'ips' => $ips, 'reason' => $reason];
    }
}
