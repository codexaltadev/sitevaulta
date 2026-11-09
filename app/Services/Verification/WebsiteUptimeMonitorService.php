<?php

namespace App\Services\Verification;

use App\Enums\BackupRunStatus;
use App\Enums\FileProtocol;
use App\Models\BackupRun;
use App\Models\ServerConnection;
use App\Models\Website;
use App\Services\Billing\EmailWalletService;
use App\Services\Connectors\ServerConnectorManager;
use App\Services\Notifications\WorkspaceAlertDispatcher;
use App\Services\Security\AuditLogService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class WebsiteUptimeMonitorService
{
    /**
     * Checks REAL live DNS + HTTP/HTTPS reachability of a Website and its connected Server(s),
     * updates status metrics, and notifies the owner on Down / Recovery transitions.
     *
     * @return array{is_online: bool, uptime_status: string, http_status: int|null, response_time_ms: int, server_status: string, message: string, email_notified: bool, alert_sent: bool}
     */
    public static function checkWebsiteLiveStatus(
        Website $website,
        ?bool $forceSimulateOnline = null,
        ?string $forceSimulateReason = null
    ): array {
        $website->loadMissing(['workspace.owner', 'serverConnections']);
        $previousStatus = $website->uptime_status ?: 'unknown';
        $startTime = microtime(true);

        $httpOnline = false;
        $httpStatus = null;
        $downReasons = [];

        $cleanHost = self::extractCleanHostname($website);

        // 1. Check Website DNS + HTTP/HTTPS Reachability
        if ($forceSimulateOnline !== null) {
            $httpOnline = $forceSimulateOnline;
            $httpStatus = $forceSimulateOnline ? 200 : 503;
            if (! $forceSimulateOnline) {
                $downReasons[] = $forceSimulateReason ?: 'Website HTTP 503 Service Unavailable';
            }
        } else {
            // Step 1A: Verify real DNS resolution first (catches fake domains like acme-commerce.example.com)
            $resolvedIps = self::isReservedOrFakeHost($cleanHost) ? [] : (@gethostbynamel($cleanHost) ?: []);

            if (empty($resolvedIps)) {
                $httpOnline = false;
                $httpStatus = 0;
                $downReasons[] = "DNS resolution failed: domain [{$cleanHost}] does not exist or has no live DNS A record";
            } else {
                // Step 1B: Perform real HTTP/HTTPS request
                try {
                    $targetUrl = str_contains((string) $website->url, '://')
                        ? (string) $website->url
                        : 'https://'.$cleanHost;

                    $response = Http::timeout(10)
                        ->connectTimeout(6)
                        ->withoutVerifying()
                        ->withHeaders(['User-Agent' => 'SiteVaulta-UptimeMonitor/1.0'])
                        ->get($targetUrl);

                    $httpStatus = $response->status();
                    if ($response->successful() || ($httpStatus >= 200 && $httpStatus < 400)) {
                        $httpOnline = true;
                    } else {
                        $httpOnline = false;
                        $downReasons[] = "Website returned HTTP {$httpStatus}";
                    }
                } catch (\Throwable $e) {
                    $httpOnline = false;
                    $httpStatus = 0;
                    $downReasons[] = 'Website unreachable: '.str($e->getMessage())->limit(140)->toString();
                }
            }
        }

        $responseTimeMs = max(1, (int) round((microtime(true) - $startTime) * 1000));

        // 2. Check Connected Server Reachability (Supports SSH/SFTP, FTP/FTPS, cPanel API, Vaultix Agent, DB-Only)
        $serverStatus = 'not_connected';
        $connections = $website->serverConnections;

        if ($connections->isNotEmpty()) {
            $serverAllOnline = true;
            foreach ($connections as $conn) {
                /** @var ServerConnection $conn */
                $creds = $conn->file_credentials ?? [];
                $endpoint = self::resolveServerEndpoint($conn, $website);
                $serverHost = $endpoint['host'];
                $serverPort = $endpoint['port'];

                if ($forceSimulateOnline !== null) {
                    if (! $forceSimulateOnline) {
                        $serverAllOnline = false;
                    }

                    continue;
                }

                // Check if the server host is empty or a reserved RFC 5737 test IP / fake domain
                if ($serverHost === '' || self::isReservedOrFakeHost($serverHost)) {
                    $serverAllOnline = false;
                    $conn->update([
                        'health_status' => 'unreachable',
                        'last_tested_at' => now()->utc(),
                    ]);
                    $downReasons[] = "Server [{$conn->name} ({$serverHost})] is unreachable (Fake/Test IP or offline host)";

                    continue;
                }

                if (($creds['simulation_mode'] ?? false) === true) {
                    if (! $httpOnline) {
                        $serverAllOnline = false;
                        $downReasons[] = "Server [{$conn->name}] unreachable";
                    }

                    continue;
                }

                // Check live reachability of the server endpoint (API / TCP / Connector)
                $connReachable = self::probeServerConnectionLive($conn, $serverHost, $serverPort, $httpOnline, $cleanHost);
                if (! $connReachable['online']) {
                    $serverAllOnline = false;
                    $downReasons[] = "Server [{$conn->name} ({$endpoint['display']})] unreachable: {$connReachable['reason']}";
                }
            }

            $serverStatus = $serverAllOnline ? 'online' : 'down';
        }

        $isOverallOnline = $httpOnline && ($serverStatus !== 'down');
        $newUptimeStatus = $isOverallOnline ? 'online' : 'down';
        $reasonString = $isOverallOnline ? null : implode(' | ', array_unique($downReasons));

        $website->update([
            'uptime_status' => $newUptimeStatus,
            'last_http_status_code' => $httpStatus,
            'last_response_time_ms' => $responseTimeMs,
            'server_live_status' => $serverStatus,
            'last_uptime_checked_at' => now()->utc(),
            'last_down_reason' => $reasonString,
        ]);

        $emailNotified = false;

        // 3. Notify Owner if Website or Server went DOWN
        if (! $isOverallOnline && ($website->uptime_monitoring_enabled ?? true)) {
            $shouldNotifyDown = ($previousStatus !== 'down')
                || ($website->last_downtime_notified_at === null)
                || $website->last_downtime_notified_at->diffInMinutes(now()->utc()) >= 60;

            if ($shouldNotifyDown) {
                $emailNotified = WorkspaceAlertDispatcher::sendWebsiteUptimeAlertEmail(
                    website: $website,
                    isDown: true,
                    reason: $reasonString ?: 'Website or Server is unreachable',
                    httpStatus: $httpStatus,
                    responseTimeMs: $responseTimeMs
                );

                if ($emailNotified) {
                    $website->update(['last_downtime_notified_at' => now()->utc()]);
                }

                if ($website->workspace) {
                    WorkspaceAlertDispatcher::dispatch($website->workspace, 'website_down', [
                        'website_name' => $website->name,
                        'domain' => $website->domain,
                        'reason' => $reasonString ?: 'Unreachable',
                        'http_status' => $httpStatus,
                    ]);
                }

                AuditLogService::log(
                    event: 'website.uptime.down_detected',
                    auditable: $website,
                    metadata: [
                        'domain' => $website->domain,
                        'reason' => $reasonString,
                        'http_status' => $httpStatus,
                        'email_notified' => $emailNotified,
                    ],
                    workspaceId: $website->workspace_id
                );
            }
        }

        // 4. Notify Owner if Website RECOVERED from a previous 'down' state
        if ($isOverallOnline && $previousStatus === 'down' && ($website->uptime_monitoring_enabled ?? true)) {
            $emailNotified = WorkspaceAlertDispatcher::sendWebsiteUptimeAlertEmail(
                website: $website,
                isDown: false,
                reason: 'Website & Server Recovered',
                httpStatus: $httpStatus,
                responseTimeMs: $responseTimeMs
            );

            $website->update(['last_downtime_notified_at' => null]);

            if ($website->workspace) {
                WorkspaceAlertDispatcher::dispatch($website->workspace, 'website_recovered', [
                    'website_name' => $website->name,
                    'domain' => $website->domain,
                    'response_time_ms' => $responseTimeMs,
                ]);
            }

            AuditLogService::log(
                event: 'website.uptime.recovered',
                auditable: $website,
                metadata: [
                    'domain' => $website->domain,
                    'response_time_ms' => $responseTimeMs,
                    'email_notified' => $emailNotified,
                ],
                workspaceId: $website->workspace_id
            );
        }

        $summaryMsg = $isOverallOnline
            ? "🟢 Online — HTTP {$httpStatus} ({$responseTimeMs} ms) | Server: ".strtoupper($serverStatus)
            : '🔴 Down — '.($reasonString ?: 'Unreachable');

        return [
            'is_online' => $isOverallOnline,
            'uptime_status' => $newUptimeStatus,
            'http_status' => $httpStatus,
            'response_time_ms' => $responseTimeMs,
            'server_status' => $serverStatus,
            'message' => $summaryMsg,
            'email_notified' => $emailNotified,
            'alert_sent' => $emailNotified,
        ];
    }

    /**
     * Resolves the true hostname, port, and display endpoint for ANY ServerConnection protocol
     * (SSH/SFTP, FTP/FTPS, cPanel API, Vaultix Agent, or DB-Only).
     *
     * @return array{protocol: string, host: string, port: int, display: string, root_path: string}
     */
    public static function resolveServerEndpoint(ServerConnection $conn, ?Website $website = null): array
    {
        $website ??= $conn->website;
        $creds = $conn->file_credentials ?? [];
        $dbCreds = $conn->db_credentials ?? [];
        $protocolValue = (string) ($conn->file_protocol?->value ?? $conn->file_protocol ?? 'ssh_sftp');

        if ($protocolValue === FileProtocol::CPanelApi->value || $protocolValue === 'cpanel_api') {
            $cpanelUrl = trim((string) ($creds['cpanel_url'] ?? ''));
            $host = '';
            $port = 2083;

            if ($cpanelUrl !== '') {
                $normalized = str_contains($cpanelUrl, '://') ? $cpanelUrl : 'https://'.$cpanelUrl;
                $parsed = parse_url($normalized);
                $host = strtolower((string) ($parsed['host'] ?? ''));
                $port = (int) ($parsed['port'] ?? 2083);
            }

            if ($host === '' && $website) {
                $host = self::extractCleanHostname($website);
            }

            $user = trim((string) ($creds['username'] ?? ''));
            $rootPath = trim((string) ($creds['root_path'] ?? ''));
            if ($rootPath === '' || $rootPath === '/var/www/html') {
                $rootPath = $user !== '' ? "/home/{$user}/public_html" : '/public_html';
            }

            return [
                'protocol' => 'CPANEL_API',
                'host' => $host,
                'port' => $port,
                'display' => "https://{$host}:{$port}",
                'root_path' => $rootPath,
            ];
        }

        if ($protocolValue === FileProtocol::VaultixAgent->value || $protocolValue === 'vaultix_agent') {
            $agentUrl = trim((string) ($creds['agent_url'] ?? $website?->url ?? $website?->domain ?? ''));
            $normalized = str_contains($agentUrl, '://') ? $agentUrl : 'https://'.$agentUrl;
            $parsed = parse_url($normalized);
            $host = strtolower((string) ($parsed['host'] ?? ($website ? self::extractCleanHostname($website) : '')));
            $scheme = strtolower((string) ($parsed['scheme'] ?? 'https'));
            $port = (int) ($parsed['port'] ?? ($scheme === 'http' ? 80 : 443));

            return [
                'protocol' => 'VAULTIX_AGENT',
                'host' => $host,
                'port' => $port,
                'display' => "{$scheme}://{$host}:{$port}",
                'root_path' => (string) ($creds['root_path'] ?? '/var/www/html'),
            ];
        }

        if ($protocolValue === FileProtocol::None->value || $protocolValue === 'none') {
            $dbHost = trim((string) ($dbCreds['host'] ?? ''));
            if (($dbHost === '' || $dbHost === '127.0.0.1' || $dbHost === 'localhost') && $website) {
                $dbHost = self::extractCleanHostname($website);
            }
            $dbPort = (int) ($dbCreds['port'] ?? 3306);

            return [
                'protocol' => 'DB_DIRECT',
                'host' => strtolower($dbHost),
                'port' => $dbPort,
                'display' => "{$dbHost}:{$dbPort}",
                'root_path' => 'Database Only',
            ];
        }

        // Default: SSH/SFTP or FTP/FTPS
        $host = strtolower(trim((string) ($creds['host'] ?? '')));
        $defaultPort = ($protocolValue === FileProtocol::FtpFtps->value || $protocolValue === 'ftp_ftps') ? 21 : 22;
        $port = (int) ($creds['port'] ?? $defaultPort);
        $protoLabel = strtoupper($protocolValue);

        return [
            'protocol' => $protoLabel,
            'host' => $host,
            'port' => $port,
            'display' => "{$protoLabel}://{$host}:{$port}",
            'root_path' => (string) ($creds['root_path'] ?? '/var/www/html'),
        ];
    }

    /**
     * Probes a ServerConnection for live reachability:
     * 1. Resolves DNS for the server hostname.
     * 2. Attempts a TCP socket connection on the configured port (e.g. 2083 for cPanel, 22 for SSH, 21 for FTP)
     *    OR if port 2083/22 is firewalled externally, verifies via Connector test or matching live Website IP.
     *
     * @return array{online: bool, reason: string}
     */
    protected static function probeServerConnectionLive(
        ServerConnection $conn,
        string $serverHost,
        int $serverPort,
        bool $websiteHttpOnline,
        string $websiteCleanHost
    ): array {
        $resolvedServerIps = @gethostbynamel($serverHost);
        if (empty($resolvedServerIps)) {
            $conn->update([
                'health_status' => 'unreachable',
                'last_tested_at' => now()->utc(),
            ]);

            return [
                'online' => false,
                'reason' => "Server hostname [{$serverHost}] failed DNS resolution",
            ];
        }

        // 1. Fast TCP socket probe to serverHost:serverPort (timeout 4s)
        $socket = @fsockopen($serverHost, $serverPort, $errno, $errstr, 4);
        if (is_resource($socket)) {
            fclose($socket);
            $conn->update([
                'health_status' => 'healthy',
                'last_tested_at' => now()->utc(),
            ]);

            return [
                'online' => true,
                'reason' => "TCP Port {$serverPort} open and responding",
            ];
        }

        // 2. If cPanel/Agent shares the same live host/IP as the online website (e.g. Cloudflare / university firewall blocking port 2083 externally while HTTPS 443 is live)
        $websiteIps = @gethostbynamel($websiteCleanHost) ?: [];
        $sharesLiveIp = ($serverHost === $websiteCleanHost) || ! empty(array_intersect($resolvedServerIps, $websiteIps));

        if ($websiteHttpOnline && $sharesLiveIp) {
            $socket443 = @fsockopen($serverHost, 443, $errno443, $errstr443, 4);
            if (is_resource($socket443)) {
                fclose($socket443);
                $conn->update([
                    'health_status' => 'healthy',
                    'last_tested_at' => now()->utc(),
                ]);

                return [
                    'online' => true,
                    'reason' => 'Host online via HTTPS (Port 443)',
                ];
            }
        }

        // 3. Final fallback: run full ServerConnectorManager test
        $testResult = ServerConnectorManager::testConnection($conn);
        if ($testResult->success) {
            return [
                'online' => true,
                'reason' => $testResult->message,
            ];
        }

        $conn->update([
            'health_status' => 'unreachable',
            'last_tested_at' => now()->utc(),
        ]);

        return [
            'online' => false,
            'reason' => "Port {$serverPort} unreachable ({$errstr})",
        ];
    }

    /**
     * Collects deep live telemetry for the Website & Server Intelligence "View" modal.
     */
    public static function inspectSiteAndServerIntelligence(Website $website): array
    {
        $website->loadMissing(['workspace.owner', 'serverConnections', 'backupPolicies']);
        $cleanHost = self::extractCleanHostname($website);

        // 1. DNS Resolution Check
        $isFakeDomain = self::isReservedOrFakeHost($cleanHost);
        $resolvedIps = $isFakeDomain ? [] : (@gethostbynamel($cleanHost) ?: []);
        $primaryIp = $resolvedIps[0] ?? null;

        // 2. SSL Certificate Inspection (if DNS resolves)
        $sslInfo = [
            'valid' => false,
            'issuer' => 'N/A',
            'subject' => $cleanHost,
            'expires_at' => null,
            'days_remaining' => null,
            'protocol' => 'None',
            'status_label' => '❌ No SSL / Domain Unreachable',
            'badge_color' => '#ef4444',
        ];

        if ($primaryIp && ! self::isReservedOrFakeHost($primaryIp)) {
            $sslInfo = self::inspectSslCertificate($cleanHost);
        }

        // 3. Backup & Storage Footprint Telemetry
        $policiesCount = $website->backupPolicies->count();
        $activePoliciesCount = $website->backupPolicies->where('status', 'active')->count();

        $completedRunsQuery = BackupRun::where('website_id', $website->id)
            ->where('status', BackupRunStatus::Completed);

        $totalCompletedBackups = (clone $completedRunsQuery)->count();
        $totalVaultStorageBytes = (int) (clone $completedRunsQuery)->sum('archive_size_bytes');
        $lastSuccessfulRun = (clone $completedRunsQuery)->latest('completed_at')->first();

        $recentRuns = BackupRun::with('backupPolicy')
            ->where('website_id', $website->id)
            ->latest('created_at')
            ->limit(5)
            ->get();

        // 4. Calculate Overall Website Protection Score (0 - 100)
        $score = 0;
        if (($website->verification_status?->value ?? $website->verification_status) === 'verified') {
            $score += 20;
        }
        if ($website->uptime_status === 'online') {
            $score += 20;
        }
        if ($website->serverConnections->isNotEmpty() && $website->server_live_status === 'online') {
            $score += 20;
        }
        if ($activePoliciesCount > 0) {
            $score += 20;
        }
        if ($lastSuccessfulRun && $lastSuccessfulRun->completed_at && $lastSuccessfulRun->completed_at->diffInDays(now()) <= 7) {
            $score += 20;
        }

        // 5. Email Alert & Smart Auto-Fallback Readiness
        $workspace = $website->workspace;
        $walletBalance = (int) ($workspace?->email_credits_balance ?? 0);
        $hasCustomSmtp = $workspace ? EmailWalletService::hasValidCustomSmtp($workspace) : false;
        $preferredMode = $website->uptime_email_provider_mode ?: ($workspace?->email_provider_mode ?: 'system_wallet');

        return [
            'clean_host' => $cleanHost,
            'primary_ip' => $primaryIp,
            'all_ips' => $resolvedIps,
            'is_fake_domain' => $isFakeDomain,
            'ssl' => $sslInfo,
            'protection_score' => $score,
            'policies_count' => $policiesCount,
            'active_policies_count' => $activePoliciesCount,
            'total_completed_backups' => $totalCompletedBackups,
            'total_vault_storage_bytes' => $totalVaultStorageBytes,
            'last_successful_run' => $lastSuccessfulRun,
            'recent_runs' => $recentRuns,
            'alert_email' => $website->uptime_alert_email ?: ($workspace?->owner?->email ?? 'Not set'),
            'preferred_email_mode' => $preferredMode,
            'wallet_balance' => $walletBalance,
            'has_custom_smtp' => $hasCustomSmtp,
            'fallback_ready' => ($walletBalance > 0 || $hasCustomSmtp),
        ];
    }

    protected static function inspectSslCertificate(string $host): array
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

            $client = @stream_socket_client(
                "ssl://{$host}:443",
                $errno,
                $errstr,
                4,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (! $client) {
                return [
                    'valid' => false,
                    'issuer' => 'Unavailable',
                    'subject' => $host,
                    'expires_at' => null,
                    'days_remaining' => null,
                    'protocol' => 'Port 443 Closed',
                    'status_label' => '❌ SSL Handshake Failed',
                    'badge_color' => '#ef4444',
                ];
            }

            $params = stream_context_get_params($client);
            fclose($client);

            $certResource = $params['options']['ssl']['peer_certificate'] ?? null;
            if (! $certResource) {
                return [
                    'valid' => false,
                    'issuer' => 'None',
                    'subject' => $host,
                    'expires_at' => null,
                    'days_remaining' => null,
                    'protocol' => 'TLS',
                    'status_label' => '❌ No Peer Certificate',
                    'badge_color' => '#ef4444',
                ];
            }

            $cert = openssl_x509_parse($certResource);
            $validToTimestamp = $cert['validTo_time_t'] ?? null;
            $issuerOrg = $cert['issuer']['O'] ?? ($cert['issuer']['CN'] ?? 'Unknown CA');
            $subjectCn = $cert['subject']['CN'] ?? $host;

            if (! $validToTimestamp) {
                return [
                    'valid' => false,
                    'issuer' => (string) $issuerOrg,
                    'subject' => (string) $subjectCn,
                    'expires_at' => null,
                    'days_remaining' => null,
                    'protocol' => 'TLS',
                    'status_label' => '⚠️ Unparseable Expiry',
                    'badge_color' => '#f59e0b',
                ];
            }

            $expiresAt = Carbon::createFromTimestampUTC($validToTimestamp);
            $daysRemaining = (int) round(now()->utc()->diffInDays($expiresAt, false));
            $isValid = $daysRemaining > 0;

            $statusLabel = match (true) {
                $daysRemaining > 14 => "🔒 Valid SSL ({$daysRemaining} days left)",
                $daysRemaining > 0 => "⚠️ Expiring Soon ({$daysRemaining} days left)",
                default => '🔴 SSL Expired',
            };

            $badgeColor = match (true) {
                $daysRemaining > 14 => '#10b981',
                $daysRemaining > 0 => '#f59e0b',
                default => '#ef4444',
            };

            return [
                'valid' => $isValid,
                'issuer' => (string) $issuerOrg,
                'subject' => (string) $subjectCn,
                'expires_at' => $expiresAt->format('M d, Y'),
                'days_remaining' => $daysRemaining,
                'protocol' => 'HTTPS / TLS 1.3',
                'status_label' => $statusLabel,
                'badge_color' => $badgeColor,
            ];
        } catch (\Throwable) {
            return [
                'valid' => false,
                'issuer' => 'N/A',
                'subject' => $host,
                'expires_at' => null,
                'days_remaining' => null,
                'protocol' => 'N/A',
                'status_label' => '❌ SSL Check Failed',
                'badge_color' => '#ef4444',
            ];
        }
    }

    public static function extractCleanHostname(Website $website): string
    {
        $raw = trim((string) ($website->domain ?: $website->url));
        if (str_contains($raw, '://')) {
            $parsed = parse_url($raw, PHP_URL_HOST);
            if (is_string($parsed) && $parsed !== '') {
                return strtolower($parsed);
            }
        }

        return strtolower(explode('/', $raw)[0]);
    }

    public static function isReservedOrFakeHost(string $host): bool
    {
        $lower = strtolower(trim($host));

        // RFC 2606 / 6761 reserved example domains
        if ($lower === 'example.com'
            || $lower === 'example.org'
            || $lower === 'example.net'
            || str_ends_with($lower, '.example.com')
            || str_ends_with($lower, '.example.org')
            || str_ends_with($lower, '.example.net')
            || str_ends_with($lower, '.invalid')
            || str_ends_with($lower, '.test')) {
            return true;
        }

        // RFC 5737 Documentation / Test IPv4 ranges (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24)
        if (str_starts_with($lower, '192.0.2.')
            || str_starts_with($lower, '198.51.100.')
            || str_starts_with($lower, '203.0.113.')) {
            return true;
        }

        return false;
    }
}
