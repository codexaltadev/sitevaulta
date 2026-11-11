<?php

namespace App\Services\Monitoring;

/**
 * Recognises responses where a WAF / bot protection / rate limiter stopped our check, as opposed to
 * the site itself failing. Such a "down" usually means the site is fine for visitors but our probe
 * IP must be allowlisted, so it gets its own root cause and allowlisting hint.
 */
class FirewallBlockDetector
{
    /**
     * @param  array<string, string|list<string>>  $headers
     * @return string|null the blocker's name (e.g. "Cloudflare"), or null when the failure is not a block
     */
    public function detect(int $status, array $headers, string $body): ?string
    {
        if ($status < 400) {
            return null;
        }

        $headers = array_change_key_case(array_map(fn (string|array $value): string => mb_strtolower(implode(' ', (array) $value)), $headers));
        $server = $headers['server'] ?? '';
        $body = mb_strtolower(mb_substr($body, 0, 20000));
        $isChallengeStatus = in_array($status, [403, 406, 429, 503], true);

        return match (true) {
            (isset($headers['cf-ray']) || str_contains($server, 'cloudflare'))
                && ($isChallengeStatus || str_contains($body, 'cf-chl') || str_contains($body, 'attention required') || str_contains($body, 'just a moment')) => 'Cloudflare',
            isset($headers['x-sucuri-id']) || str_contains($body, 'sucuri website firewall') => 'Sucuri',
            $isChallengeStatus && str_contains($body, 'wordfence') => 'Wordfence',
            $isChallengeStatus && str_contains($body, 'imunify360') => 'Imunify360',
            str_contains($server, 'akamaighost') && $status === 403 => 'Akamai',
            $isChallengeStatus && (str_contains($body, 'mod_security') || str_contains($body, 'modsecurity')) => 'ModSecurity',
            $status === 429 => 'rate limiting',
            default => null,
        };
    }
}
