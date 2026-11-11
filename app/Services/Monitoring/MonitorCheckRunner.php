<?php

namespace App\Services\Monitoring;

use App\Enums\MonitorIncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Runs one check for a standalone Monitor (HTTP, Keyword or Port), stores it in the time-series log,
 * and opens or resolves incidents. A failed probe is re-probed once (after a short pause) before an
 * incident is opened, and network-type failures are ignored while the probe's own network is down,
 * so neither a blip at the site nor one on our side alerts anyone.
 */
class MonitorCheckRunner
{
    public const PROBE_LOCATION = 'Primary probe';

    private const RESPONSE_BODY_SNAPSHOT_BYTES = 2048;

    /**
     * How many rotating A/AAAA addresses a DNS baseline remembers.
     */
    private const DNS_MAX_KNOWN_ADDRESSES = 20;

    public function __construct(
        private NetworkProbe $networkProbe,
        private IncidentAlertNotifier $alertNotifier,
        private DestinationGuard $destinationGuard,
        private HostOwnershipVerifier $hostOwnership,
        private MonitoringIdentity $monitoringIdentity,
        private FirewallBlockDetector $firewallBlockDetector,
        private ProbeHealthCheck $probeHealth,
        private SlowResponseWatcher $slowResponseWatcher,
    ) {}

    /**
     * Called when a cron job hits its heartbeat URL: stamp the signal and, if the monitor was not up,
     * run a check right away so the incident resolves without waiting for the next scheduler tick.
     */
    public function recordHeartbeat(Monitor $monitor): void
    {
        $monitor->update(['last_heartbeat_at' => now()->utc()]);

        if ($monitor->status !== MonitorStatus::Up) {
            $this->run($monitor);
        }
    }

    public function run(Monitor $monitor): MonitorCheck
    {
        $firstProbe = $this->probe($monitor);
        $confirmationProbe = null;

        // A passive heartbeat deadline gives the same answer twice, so only active probes re-check —
        // after a short pause, so a momentary network blip is not confirmed by an instant retry.
        if (! $firstProbe['is_up'] && ! $monitor->type->isPassive()) {
            Sleep::for(max(0, (int) config('monitoring.confirmation_delay_seconds', 3)))->seconds();
            $confirmationProbe = $this->probe($monitor);
        }

        $finalProbe = $confirmationProbe ?? $firstProbe;
        $now = now()->utc();

        // The probe lost its own DNS / internet: that says nothing about the site. Record nothing, alert no
        // one, and leave last_checked_at alone so the monitor is checked again on the next scheduler tick.
        if (! $finalProbe['is_up']
            && in_array($finalProbe['root_cause_type'], ProbeHealthCheck::NETWORK_ROOT_CAUSES, true)
            && ! $this->probeHealth->isHealthy()) {
            Log::warning('Monitoring probe network unavailable; check skipped', [
                'monitor_id' => $monitor->id,
                'error' => $finalProbe['error'],
            ]);
            $monitor->forceFill(['next_check_at' => $now])->saveQuietly();

            return new MonitorCheck([
                'monitor_id' => $monitor->id,
                'status' => $monitor->status,
                'region' => 'primary',
                'error_message' => $finalProbe['error'].' (skipped: the monitoring probe lost its own network)',
                'checked_at' => $now,
            ]);
        }

        // During planned maintenance a failure is not recorded, opens no incident and alerts no one,
        // so planned downtime never counts against uptime %.
        if (! $finalProbe['is_up'] && $monitor->isUnderMaintenance($now)) {
            $monitor->update(['last_checked_at' => $now]);

            return new MonitorCheck([
                'monitor_id' => $monitor->id,
                'status' => MonitorStatus::Down,
                'http_status_code' => $finalProbe['http_status'],
                'response_time_ms' => $finalProbe['response_time_ms'],
                'region' => 'primary',
                'error_message' => $finalProbe['error'].' (ignored: maintenance window)',
                'checked_at' => $now,
            ]);
        }

        $check = $monitor->checks()->create([
            'status' => $finalProbe['is_up'] ? MonitorStatus::Up : MonitorStatus::Down,
            'http_status_code' => $finalProbe['http_status'],
            'response_time_ms' => $finalProbe['response_time_ms'],
            'region' => 'primary',
            'error_message' => $finalProbe['error'],
            'checked_at' => $now,
        ]);

        if ($finalProbe['is_up']) {
            $this->recordUp($monitor, $finalProbe);
            $this->slowResponseWatcher->observe($monitor, $finalProbe['response_time_ms']);
        } else {
            $this->recordDown($monitor, $firstProbe, $finalProbe);
            $this->slowResponseWatcher->reset($monitor);
        }

        // DNS monitors learn their baseline from the records they see first.
        if (array_key_exists('dns_baseline', $finalProbe) && $finalProbe['dns_baseline'] !== $monitor->dns_baseline) {
            $monitor->forceFill(['dns_baseline' => $finalProbe['dns_baseline'] ?: null])->saveQuietly();
        }

        return $check;
    }

    /**
     * @return array{is_up: bool, http_status: int|null, response_time_ms: int|null, error: string|null, root_cause_type: string|null, request_snapshot: array<string, mixed>, response_headers: array<string, mixed>|null, response_body: string|null}
     */
    public function probe(Monitor $monitor): array
    {
        return match ($monitor->type) {
            MonitorType::Port => $this->probePort($monitor),
            MonitorType::Ping => $this->probePing($monitor),
            MonitorType::Dns => $this->probeDns($monitor),
            MonitorType::Ssl => $this->probeSsl($monitor),
            MonitorType::Domain => $this->probeDomain($monitor),
            MonitorType::Heartbeat => $this->probeHeartbeat($monitor),
            default => $this->probeHttp($monitor),
        };
    }

    /**
     * @return array{is_up: bool, http_status: int|null, response_time_ms: int|null, error: string|null, root_cause_type: string|null, request_snapshot: array<string, mixed>, response_headers: array<string, mixed>|null, response_body: string|null}
     */
    private function probePing(Monitor $monitor): array
    {
        $host = (string) $monitor->host;
        $timeout = min(10, $monitor->timeout_seconds);
        $destination = $this->destinationGuard->inspectHost($host);

        if (! $destination['allowed']) {
            return $this->blockedResult((string) $destination['reason'], ['ping' => $host]);
        }

        // Ping and TCP-connect the validated IP itself, never a name that could re-resolve elsewhere.
        $target = $destination['ips'][0] ?? $host;
        $result = $this->networkProbe->ping($target, $timeout);
        $isDnsFailure = $destination['ips'] === [] || str_starts_with((string) $result['error'], 'DNS');

        if ($result['reachable']) {
            return $this->result(true, null, 'ping', ['ping' => $host], $result['latency_ms'], probeMethod: 'icmp');
        }

        // Many servers drop ICMP at the firewall while serving traffic normally. Unless the monitor is
        // strict, a TCP connect on 443 then 80 decides reachability, so a blocked ping is not an outage.
        if (! $isDnsFailure && ! $monitor->ping_strict_icmp) {
            foreach ([443, 80] as $port) {
                $tcp = $this->networkProbe->tcpConnect($target, $port, $timeout);

                if ($tcp['connected']) {
                    return $this->result(true, null, 'ping', ['ping' => $host, 'tcp_fallback_port' => $port], $tcp['latency_ms'], probeMethod: "tcp:{$port}");
                }
            }
        }

        return $this->result(
            isUp: false,
            error: match (true) {
                $isDnsFailure => 'DNS Resolving problem',
                $monitor->ping_strict_icmp => $result['error'],
                default => 'Host unreachable (no ping reply and TCP 443/80 closed)',
            },
            rootCauseType: $isDnsFailure ? 'dns' : 'ping',
            requestSnapshot: ['ping' => $host, 'strict_icmp' => $monitor->ping_strict_icmp],
            responseTimeMs: null,
            probeMethod: 'icmp',
        );
    }

    /**
     * @return array{is_up: bool, http_status: int|null, response_time_ms: int|null, error: string|null, root_cause_type: string|null, request_snapshot: array<string, mixed>, response_headers: array<string, mixed>|null, response_body: string|null}
     */
    private function probeDns(Monitor $monitor): array
    {
        $startedAt = microtime(true);
        $types = $monitor->dnsRecordTypes();
        $snapshot = ['dns' => $monitor->host, 'types' => $types];
        $current = [];

        foreach ($types as $type) {
            $records = $this->networkProbe->dnsRecords((string) $monitor->host, $type);

            // Our resolver failing (timeout / SERVFAIL) says nothing about the records: inconclusive.
            if ($records === null) {
                return $this->result(true, null, 'dns', $snapshot, $this->elapsedMs($startedAt), probeMethod: 'dns:inconclusive');
            }

            $records = array_values(array_unique($records));
            sort($records);
            $current[$type] = $records;
        }

        // The baseline is what the records looked like when first seen. Types that have records but no
        // baseline yet are learned silently; a change from the baseline is the alert (DNS hijack / mistake).
        $baseline = (array) ($monitor->dns_baseline ?? []);
        $changes = [];

        foreach ($current as $type => $records) {
            if (! array_key_exists($type, $baseline)) {
                if ($records !== []) {
                    $baseline[$type] = $records;
                }

                continue;
            }

            if ($records === $baseline[$type]) {
                continue;
            }

            // Cloud load balancers rotate A/AAAA addresses. While at least one address is already known,
            // treat new ones as rotation and remember them; a set with no known address is a real move.
            if (in_array($type, ['A', 'AAAA'], true) && $records !== [] && array_intersect($records, $baseline[$type]) !== []) {
                $learned = array_values(array_unique([...$baseline[$type], ...$records]));
                sort($learned);
                $baseline[$type] = array_slice($learned, -self::DNS_MAX_KNOWN_ADDRESSES);

                continue;
            }

            $changes[] = $records === []
                ? "{$type} record removed (was ".implode(', ', $baseline[$type]).')'
                : "{$type} changed: ".implode(', ', $baseline[$type]).' → '.implode(', ', $records);
        }

        $baseline = array_intersect_key($baseline, $current);
        $responseBody = collect($current)->map(fn (array $records, string $type): string => "{$type}: ".($records === [] ? '(none)' : implode(', ', $records)))->implode("\n");

        if ($changes !== []) {
            return [
                ...$this->result(false, implode('; ', $changes), 'dns', $snapshot, $this->elapsedMs($startedAt), $responseBody, probeMethod: 'dns:ok'),
                'dns_baseline' => $baseline,
            ];
        }

        // Selected types that have never had records (e.g. CNAME on a root domain) are a setup mistake,
        // not an outage: "Unknown" until they are unticked or the record appears.
        $neverSeen = array_keys(array_diff_key($current, $baseline));

        return [
            ...$this->result(true, null, 'dns', $snapshot, $this->elapsedMs($startedAt), probeMethod: $neverSeen === [] ? 'dns:ok' : 'dns:misconfigured'),
            'dns_baseline' => $baseline,
        ];
    }

    /**
     * @return array{is_up: bool, http_status: int|null, response_time_ms: int|null, error: string|null, root_cause_type: string|null, request_snapshot: array<string, mixed>, response_headers: array<string, mixed>|null, response_body: string|null}
     */
    private function probeSsl(Monitor $monitor): array
    {
        $startedAt = microtime(true);
        $port = $monitor->port ?: 443;
        $destination = $this->destinationGuard->inspectHost((string) $monitor->host);

        if (! $destination['allowed']) {
            return $this->blockedResult((string) $destination['reason'], ['tls' => "{$monitor->host}:{$port}"]);
        }

        $certificate = $this->networkProbe->sslCertificate((string) $monitor->host, $port, min(10, $monitor->timeout_seconds), $destination['ips'][0] ?? null);

        $error = $certificate['error'];

        if ($certificate['expires_at'] !== null) {
            $error = $this->expiryError('SSL certificate', $certificate['expires_at'], $monitor->expiry_threshold_days);
        }

        return $this->result(
            isUp: $error === null,
            error: $error,
            rootCauseType: 'ssl',
            requestSnapshot: ['tls' => "{$monitor->host}:{$port}"],
            responseTimeMs: $this->elapsedMs($startedAt),
            responseBody: $certificate['expires_at'] !== null
                ? "Issuer: {$certificate['issuer']}\nSubject: {$certificate['subject']}\nExpires: {$certificate['expires_at']->toDateTimeString()} UTC"
                : null,
            expiresAt: $certificate['expires_at'],
        );
    }

    /**
     * Reads the registration expiry date over RDAP (the JSON successor of WHOIS).
     *
     * @return array{is_up: bool, http_status: int|null, response_time_ms: int|null, error: string|null, root_cause_type: string|null, request_snapshot: array<string, mixed>, response_headers: array<string, mixed>|null, response_body: string|null}
     */
    private function probeDomain(Monitor $monitor): array
    {
        $startedAt = microtime(true);
        // Registries only know the registered domain, never a sub-domain such as elearn.example.edu.bd.
        $domain = RegistrableDomain::of($monitor->host) ?? (string) $monitor->host;
        $lookupUrl = 'https://rdap.org/domain/'.rawurlencode($domain);

        // Source order: a date the user typed in, the registry over RDAP, a date another check of the same
        // registered domain already knows, then the last date we knew. Some registries (e.g. .bd) publish
        // no RDAP/WHOIS data at all; an unreadable date is "unknown", never an outage, so it opens no incident.
        [$expiresAt, $source] = match (true) {
            $monitor->manual_expires_at !== null => [CarbonImmutable::parse($monitor->manual_expires_at->toDateString(), 'UTC')->endOfDay(), 'manual'],
            ($rdapExpiry = $this->rdapExpiry($lookupUrl, $monitor->timeout_seconds)) !== null => [$rdapExpiry, 'rdap'],
            ($sharedExpiry = $this->sharedDomainExpiry($monitor, $domain)) !== null => [$sharedExpiry, 'shared'],
            $monitor->expires_at !== null => [CarbonImmutable::instance($monitor->expires_at), 'cached'],
            default => [null, 'unavailable'],
        };

        $error = $expiresAt === null
            ? null
            : $this->expiryError('Domain registration', $expiresAt, $monitor->expiry_threshold_days);

        return $this->result(
            isUp: $error === null,
            error: $error,
            rootCauseType: 'domain',
            requestSnapshot: ['method' => 'GET', 'url' => $lookupUrl, 'source' => $source],
            responseTimeMs: $this->elapsedMs($startedAt),
            responseBody: $expiresAt !== null ? "Expires: {$expiresAt->toDateTimeString()} UTC ({$source})" : null,
            expiresAt: $expiresAt,
            probeMethod: $source,
        );
    }

    /**
     * The expiry date another domain check in the same workspace knows for this registered domain — a date
     * typed in by hand, or one read from the registry — so a .bd date only has to be entered once.
     */
    private function sharedDomainExpiry(Monitor $monitor, string $domain): ?CarbonImmutable
    {
        $sibling = Monitor::query()
            ->where('workspace_id', $monitor->workspace_id)
            ->where('type', MonitorType::Domain)
            ->whereKeyNot($monitor->getKey())
            ->where(fn ($query) => $query->whereNotNull('manual_expires_at')
                ->orWhere(fn ($query) => $query->whereNotNull('expires_at')->whereIn('last_probe_method', ['rdap', 'manual'])))
            ->get(['id', 'host', 'manual_expires_at', 'expires_at'])
            ->filter(fn (Monitor $candidate): bool => RegistrableDomain::of($candidate->host) === $domain)
            ->sortByDesc(fn (Monitor $candidate): bool => $candidate->manual_expires_at !== null)
            ->first();

        return match (true) {
            $sibling?->manual_expires_at !== null => CarbonImmutable::parse($sibling->manual_expires_at->toDateString(), 'UTC')->endOfDay(),
            $sibling?->expires_at !== null => CarbonImmutable::instance($sibling->expires_at),
            default => null,
        };
    }

    private function rdapExpiry(string $lookupUrl, int $timeoutSeconds): ?CarbonImmutable
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(min(20, $timeoutSeconds))
                ->acceptJson()
                ->get($lookupUrl);
        } catch (ConnectionException) {
            return null;
        }

        $expirationEvent = $response->successful()
            ? collect($response->json('events', []))->firstWhere('eventAction', 'expiration')
            : null;

        return isset($expirationEvent['eventDate'])
            ? CarbonImmutable::parse($expirationEvent['eventDate'])->utc()
            : null;
    }

    /**
     * Heartbeat monitors are passive: the job calls /heartbeat/{token}; we only check the deadline.
     *
     * @return array{is_up: bool, http_status: int|null, response_time_ms: int|null, error: string|null, root_cause_type: string|null, request_snapshot: array<string, mixed>, response_headers: array<string, mixed>|null, response_body: string|null}
     */
    private function probeHeartbeat(Monitor $monitor): array
    {
        $lastSignal = $monitor->last_heartbeat_at ?? $monitor->created_at;
        $deadline = $lastSignal->copy()->addSeconds($monitor->interval_seconds + $monitor->heartbeat_grace_seconds);
        $isLate = now()->utc()->gt($deadline);

        $error = match (true) {
            ! $isLate => null,
            $monitor->last_heartbeat_at === null => 'No heartbeat received yet',
            default => 'No heartbeat received since '.$monitor->last_heartbeat_at->toDateTimeString().' UTC',
        };

        return $this->result(
            isUp: ! $isLate,
            error: $error,
            rootCauseType: 'heartbeat',
            requestSnapshot: ['expected_every_seconds' => $monitor->interval_seconds, 'grace_seconds' => $monitor->heartbeat_grace_seconds],
            responseTimeMs: null,
        );
    }

    private function expiryError(string $subject, CarbonImmutable $expiresAt, int $thresholdDays): ?string
    {
        $daysLeft = (int) floor(now()->utc()->diffInDays($expiresAt, false));

        return match (true) {
            $daysLeft < 0 => "{$subject} expired on {$expiresAt->toDateString()}",
            $daysLeft <= $thresholdDays => "{$subject} expires in {$daysLeft} days ({$expiresAt->toDateString()})",
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $requestSnapshot
     * @return array{is_up: bool, http_status: int|null, response_time_ms: int|null, error: string|null, root_cause_type: string|null, request_snapshot: array<string, mixed>, response_headers: array<string, mixed>|null, response_body: string|null}
     */
    private function result(bool $isUp, ?string $error, string $rootCauseType, array $requestSnapshot, ?int $responseTimeMs, ?string $responseBody = null, ?CarbonImmutable $expiresAt = null, ?string $probeMethod = null): array
    {
        return [
            'is_up' => $isUp,
            'http_status' => null,
            'response_time_ms' => $responseTimeMs,
            'error' => $isUp ? null : $error,
            'root_cause_type' => $isUp ? null : $rootCauseType,
            'request_snapshot' => $requestSnapshot,
            'response_headers' => null,
            'response_body' => $isUp ? null : $responseBody,
            'expires_at' => $expiresAt,
            'probe_method' => $probeMethod,
        ];
    }

    /**
     * @return array{is_up: bool, http_status: int|null, response_time_ms: int|null, error: string|null, root_cause_type: string|null, request_snapshot: array<string, mixed>, response_headers: array<string, mixed>|null, response_body: string|null}
     */
    private function probeHttp(Monitor $monitor): array
    {
        $method = strtoupper($monitor->http_method ?: 'GET');
        $headers = ['User-Agent' => $this->monitoringIdentity->userAgent()];
        $requestSnapshot = ['method' => $method, 'url' => $monitor->url, 'headers' => $headers];
        $startedAt = microtime(true);
        $url = (string) $monitor->url;

        // Redirects are followed by hand so every hop is checked against the destination guard and
        // pinned to the validated IP — a public site cannot bounce the check into our private network.
        for ($hop = 0; ; $hop++) {
            $destination = $this->destinationGuard->inspectUrl($url);

            if (! $destination['allowed']) {
                return $this->blockedResult((string) $destination['reason'], $requestSnapshot);
            }

            $pin = $destination['ips'] === []
                ? []
                : ['curl' => [CURLOPT_RESOLVE => ["{$destination['host']}:{$destination['port']}:{$destination['ips'][0]}"]]];

            try {
                $response = Http::connectTimeout(min(10, $monitor->timeout_seconds))
                    ->timeout($monitor->timeout_seconds)
                    ->withHeaders($headers)
                    ->withOptions(['allow_redirects' => false, ...$pin])
                    ->send($hop === 0 ? $method : 'GET', $url);
            } catch (ConnectionException $exception) {
                return $this->failedConnectionResult($exception, $requestSnapshot, $startedAt);
            }

            $location = $response->header('Location');

            if (! $monitor->follow_redirects || ! $response->redirect() || $location === '' || $hop >= 5) {
                break;
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }

        $responseTimeMs = $this->elapsedMs($startedAt);
        $status = $response->status();
        $body = $response->body();
        $isUp = $status >= 200 && $status < 400;
        $error = $isUp ? null : "HTTP {$status} ".($response->reason() ?: 'Error');
        $rootCauseType = $isUp ? null : 'http';

        // A WAF / bot protection page means the site likely works for visitors but blocks our probe.
        $blocker = $isUp ? null : $this->firewallBlockDetector->detect($status, $response->headers(), $body);

        if ($blocker !== null) {
            $rootCauseType = 'firewall';
            $error = $blocker === 'rate limiting'
                ? "Rate limited (HTTP {$status}) — allowlist our monitoring IPs"
                : "Blocked by {$blocker} (HTTP {$status}) — allowlist our monitoring IPs";
        }

        if ($isUp && $monitor->type === MonitorType::Keyword) {
            $keywordFound = $monitor->keyword_case_sensitive
                ? str_contains($body, (string) $monitor->keyword)
                : str_contains(mb_strtolower($body), mb_strtolower((string) $monitor->keyword));

            if ($keywordFound !== $monitor->keyword_should_exist) {
                $isUp = false;
                $rootCauseType = 'keyword';
                $error = $monitor->keyword_should_exist
                    ? "Keyword \"{$monitor->keyword}\" not found"
                    : "Keyword \"{$monitor->keyword}\" found";
            }
        }

        return [
            'is_up' => $isUp,
            'http_status' => $status,
            'response_time_ms' => $responseTimeMs,
            'error' => $error,
            'root_cause_type' => $rootCauseType,
            'request_snapshot' => $requestSnapshot,
            'response_headers' => $isUp ? null : $response->headers(),
            'response_body' => $isUp ? null : mb_strcut($body, 0, self::RESPONSE_BODY_SNAPSHOT_BYTES),
        ];
    }

    /**
     * A check refused before any connection is made because the destination is not public.
     *
     * @param  array<string, mixed>  $requestSnapshot
     * @return array{is_up: bool, http_status: int|null, response_time_ms: int|null, error: string|null, root_cause_type: string|null, request_snapshot: array<string, mixed>, response_headers: array<string, mixed>|null, response_body: string|null}
     */
    private function blockedResult(string $reason, array $requestSnapshot): array
    {
        return $this->result(false, "Blocked: {$reason}", 'blocked', $requestSnapshot, null);
    }

    /**
     * @param  array<string, mixed>  $requestSnapshot
     * @return array{is_up: bool, http_status: int|null, response_time_ms: int|null, error: string|null, root_cause_type: string|null, request_snapshot: array<string, mixed>, response_headers: array<string, mixed>|null, response_body: string|null}
     */
    private function failedConnectionResult(ConnectionException $exception, array $requestSnapshot, float $startedAt): array
    {
        $message = $exception->getMessage();

        [$rootCauseType, $error] = match (true) {
            str_contains($message, 'Could not resolve host'), str_contains($message, 'cURL error 6') => ['dns', 'DNS Resolving problem'],
            str_contains($message, 'timed out'), str_contains($message, 'cURL error 28') => ['timeout', 'Connection timeout'],
            str_contains($message, 'SSL'), str_contains($message, 'certificate') => ['ssl', 'SSL error'],
            default => ['connection', 'Connection failure'],
        };

        return [
            'is_up' => false,
            'http_status' => null,
            'response_time_ms' => $this->elapsedMs($startedAt),
            'error' => $error,
            'root_cause_type' => $rootCauseType,
            'request_snapshot' => $requestSnapshot,
            'response_headers' => null,
            'response_body' => mb_strcut($message, 0, self::RESPONSE_BODY_SNAPSHOT_BYTES),
        ];
    }

    /**
     * @return array{is_up: bool, http_status: int|null, response_time_ms: int|null, error: string|null, root_cause_type: string|null, request_snapshot: array<string, mixed>, response_headers: array<string, mixed>|null, response_body: string|null}
     */
    private function probePort(Monitor $monitor): array
    {
        $ports = $monitor->portList();
        $snapshot = ['host' => $monitor->host, 'ports' => $ports];
        $destination = $this->destinationGuard->inspectHost((string) $monitor->host);

        if (! $destination['allowed']) {
            return $this->blockedResult((string) $destination['reason'], $snapshot);
        }

        // Re-checked every run: if the domain verification or server connection is removed, scanning stops.
        if ($monitor->workspace === null || ! $this->hostOwnership->ownership($monitor->workspace, (string) $monitor->host)['owned']) {
            return $this->blockedResult("ownership of {$monitor->host} is not verified for this workspace (Uptime Monitoring → Verified domains)", $snapshot);
        }

        if ($destination['ips'] === []) {
            return $this->result(false, 'DNS Resolving problem', 'dns', $snapshot, null);
        }

        $target = $destination['ips'][0];
        $timeout = min(10, $monitor->timeout_seconds);
        $closed = [];
        $latencies = [];
        $lines = [];

        foreach ($ports as $port) {
            $connection = $this->networkProbe->tcpConnect($target, $port, $timeout);
            $lines[] = Monitor::portLabel($port).': '.($connection['connected'] ? "open ({$connection['latency_ms']} ms)" : 'closed');

            if ($connection['connected']) {
                $latencies[] = (int) $connection['latency_ms'];
            } else {
                $closed[] = Monitor::portLabel($port);
            }
        }

        return $this->result(
            isUp: $closed === [],
            error: 'Closed: '.implode(', ', $closed),
            rootCauseType: 'port',
            requestSnapshot: $snapshot,
            responseTimeMs: $latencies === [] ? null : (int) round(array_sum($latencies) / count($latencies)),
            responseBody: implode("\n", $lines),
        );
    }

    /**
     * @param  array{is_up: bool, http_status: int|null, response_time_ms: int|null, error: string|null}  $probe
     */
    private function recordUp(Monitor $monitor, array $probe): void
    {
        $now = now()->utc();
        $statusChanged = $monitor->status !== MonitorStatus::Up;

        $monitor->update([
            'status' => MonitorStatus::Up,
            'consecutive_failures' => 0,
            'expires_at' => $probe['expires_at'] ?? $monitor->expires_at,
            'last_probe_method' => $probe['probe_method'] ?? null,
            'last_response_time_ms' => $probe['response_time_ms'],
            'last_http_status_code' => $probe['http_status'],
            'last_error' => null,
            'last_checked_at' => $now,
            'last_status_changed_at' => $statusChanged ? $now : $monitor->last_status_changed_at,
        ]);

        $incident = $monitor->incidents()
            ->where('status', MonitorIncidentStatus::Ongoing)
            ->latest('started_at')
            ->first();

        if ($incident === null) {
            return;
        }

        $incident->update([
            'status' => MonitorIncidentStatus::Resolved,
            'resolved_at' => $now,
            'duration_seconds' => (int) $incident->started_at->diffInSeconds($now),
        ]);

        $incident->events()->create([
            'type' => 'resolved',
            'message' => 'Monitor is back up'.($probe['response_time_ms'] !== null ? " ({$probe['response_time_ms']} ms)" : ''),
            'probe_location' => self::PROBE_LOCATION,
            'occurred_at' => $now,
        ]);

        $this->alertNotifier->incidentResolved($monitor, $incident);
    }

    /**
     * @param  array{is_up: bool, http_status: int|null, response_time_ms: int|null, error: string|null, root_cause_type: string|null, request_snapshot: array<string, mixed>, response_headers: array<string, mixed>|null, response_body: string|null}  $firstProbe
     * @param  array{is_up: bool, http_status: int|null, response_time_ms: int|null, error: string|null, root_cause_type: string|null, request_snapshot: array<string, mixed>, response_headers: array<string, mixed>|null, response_body: string|null}  $confirmationProbe
     */
    private function recordDown(Monitor $monitor, array $firstProbe, array $confirmationProbe): void
    {
        $now = now()->utc();
        $wasAlreadyDown = $monitor->status === MonitorStatus::Down;

        $monitor->update([
            'status' => MonitorStatus::Down,
            'consecutive_failures' => $monitor->consecutive_failures + 1,
            'expires_at' => $confirmationProbe['expires_at'] ?? $monitor->expires_at,
            'last_probe_method' => $confirmationProbe['probe_method'] ?? null,
            'last_response_time_ms' => null,
            'last_http_status_code' => $confirmationProbe['http_status'],
            'last_error' => $confirmationProbe['error'],
            'last_checked_at' => $now,
            'last_status_changed_at' => $wasAlreadyDown ? $monitor->last_status_changed_at : $now,
        ]);

        $hasOngoingIncident = $monitor->incidents()
            ->where('status', MonitorIncidentStatus::Ongoing)
            ->exists();

        if ($hasOngoingIncident) {
            return;
        }

        $incident = DB::transaction(function () use ($monitor, $firstProbe, $confirmationProbe, $now): MonitorIncident {
            $incident = $monitor->incidents()->create([
                'workspace_id' => $monitor->workspace_id,
                'status' => MonitorIncidentStatus::Ongoing,
                'root_cause_type' => (string) $confirmationProbe['root_cause_type'],
                'root_cause' => (string) $confirmationProbe['error'],
                'region' => 'primary',
                'request_snapshot' => $confirmationProbe['request_snapshot'],
                'response_headers' => $confirmationProbe['response_headers'],
                'response_body' => $confirmationProbe['response_body'],
                'started_at' => $now,
            ]);

            $incident->events()->createMany([
                [
                    'type' => 'detected',
                    'message' => "{$firstProbe['error']} detected",
                    'probe_location' => self::PROBE_LOCATION,
                    'occurred_at' => $now,
                ],
                [
                    'type' => 'confirmed',
                    'message' => $monitor->type->isPassive()
                        ? "Heartbeat deadline passed ({$monitor->heartbeat_grace_seconds}s grace period)"
                        : "{$confirmationProbe['error']} confirmed by re-check",
                    'probe_location' => self::PROBE_LOCATION,
                    'occurred_at' => $now,
                ],
            ]);

            return $incident;
        });

        $this->alertNotifier->incidentOpened($monitor, $incident);
    }

    private function elapsedMs(float $startedAt): int
    {
        return max(1, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
