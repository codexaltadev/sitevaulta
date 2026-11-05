<?php

namespace App\Services\Security;

use App\Services\Settings\SystemSettingsService;
use InvalidArgumentException;

class SsrfValidator
{
    public static function assertSafeHost(string $hostOrUrl): string
    {
        $host = $hostOrUrl;
        if (str_contains($hostOrUrl, '://')) {
            $parsed = parse_url($hostOrUrl, PHP_URL_HOST);
            if (! $parsed) {
                throw new InvalidArgumentException('Invalid URL or hostname format.');
            }
            $host = $parsed;
        }

        $host = strtolower(trim($host, '[]/ '));
        if ($host === '') {
            throw new InvalidArgumentException('Hostname cannot be empty.');
        }

        $enforceSsrf = (bool) SystemSettingsService::get('security', 'block_private_ips_ssrf', true);
        if (! $enforceSsrf) {
            return $host;
        }

        if (in_array($host, ['localhost', 'localhost.localdomain', '0.0.0.0', '::1', 'metadata.google.internal'], true)) {
            throw new InvalidArgumentException("SSRF Protection: Local or internal host [{$host}] is not permitted.");
        }

        self::assertNotCustomBlocked($host, $host);

        // If literal IP address, validate directly
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            self::assertPublicIp($host, $host);

            return $host;
        }

        // Resolve DNS records if possible
        $ips = @gethostbynamel($host) ?: [];
        foreach ($ips as $ip) {
            self::assertNotCustomBlocked($ip, $host);
            self::assertPublicIp($ip, $host);
        }

        return $host;
    }

    protected static function assertNotCustomBlocked(string $candidate, string $originalHost): void
    {
        $customBlocked = SystemSettingsService::get('security', 'ssrf_custom_blocked_ips', []);
        if (! is_array($customBlocked) || empty($customBlocked)) {
            return;
        }

        $normalizedCandidate = strtolower(trim($candidate));
        foreach ($customBlocked as $blockedEntry) {
            $rule = strtolower(trim((string) $blockedEntry));
            if ($rule === '') {
                continue;
            }

            if ($normalizedCandidate === $rule || str_starts_with($normalizedCandidate, rtrim($rule, '*'))) {
                throw new InvalidArgumentException(
                    "SSRF Protection: Host [{$originalHost}] matches custom admin blocklist rule [{$blockedEntry}]."
                );
            }
        }
    }

    protected static function assertPublicIp(string $ip, string $originalHost): void
    {
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isPublic === false) {
            throw new InvalidArgumentException(
                "SSRF Protection: Host [{$originalHost}] resolves to a private or reserved IP address ({$ip}), which is blocked by security policy."
            );
        }
    }
}
