<?php

namespace App\Services\Monitoring;

/**
 * Signs requests between the main server and its probe agents: HMAC-SHA256 over "timestamp\nbody" with the
 * location's secret. A request with a wrong signature or a timestamp more than a minute off is refused, so
 * nobody else can make an agent check things and a captured request cannot be replayed later.
 */
class ProbeSignature
{
    public const TIMESTAMP_HEADER = 'X-Probe-Timestamp';

    public const SIGNATURE_HEADER = 'X-Probe-Signature';

    /**
     * @return array<string, string>
     */
    public static function headers(string $secret, string $body, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::SIGNATURE_HEADER => self::sign($secret, $body, $timestamp),
        ];
    }

    public static function sign(string $secret, string $body, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}\n{$body}", $secret);
    }

    public static function verify(?string $secret, string $body, ?string $timestamp, ?string $signature, int $maxSkewSeconds = 60): bool
    {
        if (blank($secret) || blank($signature) || ! ctype_digit((string) $timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > $maxSkewSeconds) {
            return false;
        }

        return hash_equals(self::sign((string) $secret, $body, (int) $timestamp), (string) $signature);
    }
}
