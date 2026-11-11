<?php

namespace App\Services\Monitoring;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Low-level network primitives used by monitors (ICMP ping, DNS lookup, TLS certificate read).
 * Kept in one injectable class so the check engine can be tested without touching the network.
 */
class NetworkProbe
{
    /**
     * @return array{reachable: bool, latency_ms: int|null, error: string|null}
     */
    public function ping(string $host, int $timeoutSeconds): array
    {
        // A host starting with "-" would be read by ping as an option (argument injection).
        if (! preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.:\-]*$/', $host)) {
            return ['reachable' => false, 'latency_ms' => null, 'error' => 'Invalid hostname'];
        }

        // Resolve first so a missing domain is reported as DNS (and never retried over TCP),
        // whatever wording the local ping binary uses for resolver errors.
        if (filter_var($host, FILTER_VALIDATE_IP) === false && ! $this->resolves($host)) {
            return ['reachable' => false, 'latency_ms' => null, 'error' => 'DNS Resolving problem'];
        }

        $result = Process::timeout($timeoutSeconds + 2)
            ->run(['ping', '-c', '1', '-W', (string) max(1, $timeoutSeconds), $host]);

        if (! $result->successful()) {
            $output = trim($result->errorOutput() ?: $result->output());

            return [
                'reachable' => false,
                'latency_ms' => null,
                'error' => str_contains($output, 'unknown host') || str_contains($output, 'Name or service not known')
                    ? 'DNS Resolving problem'
                    : 'Host unreachable (no ping reply)',
            ];
        }

        preg_match('/time[=<]([\d.]+)\s*ms/', $result->output(), $matches);

        return [
            'reachable' => true,
            'latency_ms' => isset($matches[1]) ? max(1, (int) round((float) $matches[1])) : null,
            'error' => null,
        ];
    }

    /**
     * Every IPv4 and IPv6 address a hostname resolves to (the host itself when it is an IP literal).
     *
     * @return list<string>
     */
    public function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ipv4 = @gethostbynamel($host) ?: [];
        $ipv6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$ipv4, ...$ipv6]));
    }

    public function resolves(string $host): bool
    {
        return (@gethostbynamel($host) ?: []) !== []
            || (@dns_get_record($host, DNS_AAAA) ?: []) !== [];
    }

    /**
     * Opens (and immediately closes) a TCP connection — a "TCP ping" for servers whose firewall drops ICMP.
     *
     * @return array{connected: bool, latency_ms: int|null}
     */
    public function tcpConnect(string $host, int $port, int $timeoutSeconds): array
    {
        if (! preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.:\-]*$/', $host)) {
            return ['connected' => false, 'latency_ms' => null];
        }

        $startedAt = microtime(true);
        $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$host}]" : $host;
        $socket = @fsockopen($address, $port, $errorCode, $errorMessage, max(1, $timeoutSeconds));

        if ($socket === false) {
            return ['connected' => false, 'latency_ms' => null];
        }

        fclose($socket);

        return ['connected' => true, 'latency_ms' => max(1, (int) round((microtime(true) - $startedAt) * 1000))];
    }

    /**
     * @return list<string>|null record values (IP, target host, TXT text…), lower-cased, without trailing dots;
     *                           null when the resolver itself failed (timeout / SERVFAIL), which says nothing about the record
     */
    public function dnsRecords(string $host, string $recordType): ?array
    {
        $typeConstant = match ($recordType) {
            'AAAA' => DNS_AAAA,
            'CNAME' => DNS_CNAME,
            'MX' => DNS_MX,
            'NS' => DNS_NS,
            'TXT' => DNS_TXT,
            default => DNS_A,
        };

        $records = @dns_get_record($host, $typeConstant);

        if ($records === false) {
            return null;
        }

        return array_values(array_map(
            fn (array $record): string => rtrim(mb_strtolower((string) ($record['ip'] ?? $record['ipv6'] ?? $record['target'] ?? $record['txt'] ?? '')), '.'),
            $records,
        ));
    }

    /**
     * @return array{expires_at: CarbonImmutable|null, issuer: string|null, subject: string|null, error: string|null}
     */
    public function sslCertificate(string $host, int $port = 443, int $timeoutSeconds = 4, ?string $connectIp = null): array
    {
        try {
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'SNI_enabled' => true,
                    'peer_name' => $host,
                ],
            ]);

            // Connect to the pre-validated IP when given; SNI / peer_name still carries the hostname.
            $address = $connectIp === null ? $host : (str_contains($connectIp, ':') ? "[{$connectIp}]" : $connectIp);
            $client = @stream_socket_client("ssl://{$address}:{$port}", $errorCode, $errorMessage, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);

            if (! $client) {
                return ['expires_at' => null, 'issuer' => null, 'subject' => null, 'error' => "TLS handshake failed on port {$port}"];
            }

            $certificate = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
            fclose($client);

            $parsed = $certificate ? openssl_x509_parse($certificate) : false;

            if (! $parsed || ! isset($parsed['validTo_time_t'])) {
                return ['expires_at' => null, 'issuer' => null, 'subject' => null, 'error' => 'No readable certificate'];
            }

            return [
                'expires_at' => CarbonImmutable::createFromTimestampUTC($parsed['validTo_time_t']),
                'issuer' => (string) ($parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? 'Unknown CA'),
                'subject' => (string) ($parsed['subject']['CN'] ?? $host),
                'error' => null,
            ];
        } catch (Throwable) {
            return ['expires_at' => null, 'issuer' => null, 'subject' => null, 'error' => 'SSL check failed'];
        }
    }
}
