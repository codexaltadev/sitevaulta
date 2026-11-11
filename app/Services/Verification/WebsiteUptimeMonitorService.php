<?php

namespace App\Services\Verification;

use App\Enums\BackupRunStatus;
use App\Enums\FileProtocol;
use App\Enums\MonitorStatus;
use App\Models\BackupRun;
use App\Models\Monitor;
use App\Models\ServerConnection;
use App\Models\Website;
use App\Services\Billing\EmailWalletService;
use App\Services\Connectors\ServerConnectorManager;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\NetworkProbe;
use App\Services\Monitoring\WebsiteMonitorSync;
use App\Services\Notifications\WorkspaceAlertDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class WebsiteUptimeMonitorService
{
    /**
     * Live status of a website: its HTTP uptime always comes from its linked monitor (created on demand
     * when the plan allows), which records the check, opens/resolves the incident and sends the alerts.
     * Server-connection and SSL health are refreshed here because they belong to the website.
     *
     * @param  int|null  $forceSimulateSslDaysRemaining  demo/testing: pretend the certificate expires in this many days
     * @return array{is_online: bool, uptime_status: string, http_status: int|null, response_time_ms: int, server_status: string, ssl_status: string, ssl_days_remaining: int|null, ssl_expiry_alert_sent: bool, message: string, email_notified: bool, alert_sent: bool}
     */
    public static function checkWebsiteLiveStatus(Website $website, ?int $forceSimulateSslDaysRemaining = null): array
    {
        $website->loadMissing(['workspace.owner', 'serverConnections']);

        $monitor = app(WebsiteMonitorSync::class)->ensureMonitorFor($website);

        if ($monitor !== null && $monitor->status !== MonitorStatus::Paused) {
            return self::checkThroughLinkedMonitor($website, $monitor, $forceSimulateSslDaysRemaining);
        }

        // Not monitored (switched off, paused or no free monitor slot): no HTTP verdict, but the server
        // connections and the certificate are still checked.
        $health = self::refreshServerAndSslHealth($website, $forceSimulateSslDaysRemaining);

        return [
            'is_online' => false,
            'uptime_status' => 'unknown',
            'http_status' => null,
            'response_time_ms' => 0,
            'server_status' => $health['server_status'],
            'ssl_status' => $health['ssl_status'],
            'ssl_days_remaining' => $health['ssl_days_remaining'],
            'ssl_expiry_alert_sent' => $health['ssl_expiry_alert_sent'],
            'message' => ($monitor === null
                ? '⚪ Uptime is not monitored — turn on uptime monitoring or free a monitor slot in your plan.'
                : '⏸ Uptime monitoring is paused.').' Server: '.strtoupper($health['server_status']),
            'email_notified' => $health['server_alert_sent'],
            'alert_sent' => $health['server_alert_sent'],
        ];
    }

    /**
     * Website uptime is owned by its linked standalone Monitor: run that monitor (which records the check,
     * opens/resolves the incident and sends the down/recovery alerts), then refresh server + SSL health.
     *
     * @return array{is_online: bool, uptime_status: string, http_status: int|null, response_time_ms: int, server_status: string, ssl_status: string, ssl_days_remaining: int|null, ssl_expiry_alert_sent: bool, message: string, email_notified: bool, alert_sent: bool}
     */
    protected static function checkThroughLinkedMonitor(Website $website, Monitor $monitor, ?int $forceSimulateSslDaysRemaining = null): array
    {
        $check = app(MonitorCheckRunner::class)->run($monitor);
        $website->unsetRelation('uptimeMonitor');

        $health = self::refreshServerAndSslHealth($website, $forceSimulateSslDaysRemaining);
        $httpOnline = $check->status === MonitorStatus::Up;
        $isOnline = $httpOnline && $health['server_status'] !== 'down';
        $reason = collect([$httpOnline ? null : $monitor->refresh()->last_error, $health['server_reason']])->filter()->implode(' | ');

        return [
            'is_online' => $isOnline,
            'uptime_status' => $website->uptime_status,
            'http_status' => $check->http_status_code,
            'response_time_ms' => (int) $check->response_time_ms,
            'server_status' => $health['server_status'],
            'ssl_status' => $health['ssl_status'],
            'ssl_days_remaining' => $health['ssl_days_remaining'],
            'ssl_expiry_alert_sent' => $health['ssl_expiry_alert_sent'],
            'message' => $isOnline
                ? "🟢 Online — HTTP {$check->http_status_code} ({$check->response_time_ms} ms) | Server: ".strtoupper($health['server_status'])
                : '🔴 Down — '.($reason ?: 'Unreachable'),
            'email_notified' => $health['server_alert_sent'],
            'alert_sent' => $health['server_alert_sent'],
        ];
    }

    /**
     * Server-connection reachability + SSL certificate telemetry for a website whose HTTP uptime is
     * handled by its linked Monitor. Alerts the owner when the server goes down or recovers, and sends
     * the SSL expiry reminder. HTTP uptime itself is the monitor's.
     *
     * @return array{server_status: string, server_reason: string|null, server_alert_sent: bool, ssl_status: string, ssl_days_remaining: int|null, ssl_expiry_alert_sent: bool}
     */
    public static function refreshServerAndSslHealth(Website $website, ?int $forceSimulateSslDaysRemaining = null): array
    {
        $website->loadMissing(['workspace.owner', 'serverConnections']);
        $cleanHost = self::extractCleanHostname($website);
        $httpOnline = $website->uptime_status === 'online';
        $previousServerStatus = $website->server_live_status ?: 'unknown';

        $serverStatus = 'not_connected';
        $serverReasons = [];

        if ($website->serverConnections->isNotEmpty()) {
            $serverAllOnline = true;

            foreach ($website->serverConnections as $conn) {
                /** @var ServerConnection $conn */
                $endpoint = self::resolveServerEndpoint($conn, $website);

                if ($endpoint['host'] === '' || self::isReservedOrFakeHost($endpoint['host'])) {
                    $serverAllOnline = false;
                    $conn->update(['health_status' => 'unreachable', 'last_tested_at' => now()->utc()]);
                    $serverReasons[] = "Server [{$conn->name} ({$endpoint['host']})] is unreachable (Fake/Test IP or offline host)";

                    continue;
                }

                if ((($conn->file_credentials ?? [])['simulation_mode'] ?? false) === true) {
                    if (! $httpOnline) {
                        $serverAllOnline = false;
                        $serverReasons[] = "Server [{$conn->name}] unreachable";
                    }

                    continue;
                }

                $reachability = self::probeServerConnectionLive($conn, $endpoint['host'], $endpoint['port'], $httpOnline, $cleanHost);

                if (! $reachability['online']) {
                    $serverAllOnline = false;
                    $serverReasons[] = "Server [{$conn->name} ({$endpoint['display']})] unreachable: {$reachability['reason']}";
                }
            }

            $serverStatus = $serverAllOnline ? 'online' : 'down';
        }

        $sslStatus = $website->ssl_status ?: 'none';
        $sslIssuer = $website->ssl_issuer;
        $sslExpiresAt = $website->ssl_expires_at;
        $sslDaysRemaining = $website->ssl_days_remaining;
        $sslExpiresFormatted = $sslExpiresAt?->format('M d, Y');

        if ($forceSimulateSslDaysRemaining !== null) {
            $sslDaysRemaining = $forceSimulateSslDaysRemaining;
            $sslIssuer = "Let's Encrypt";
            $sslExpiresAt = now()->utc()->addDays($forceSimulateSslDaysRemaining);
            $sslExpiresFormatted = $sslExpiresAt->format('M d, Y');
            $sslStatus = $sslDaysRemaining <= 0
                ? 'expired'
                : ($sslDaysRemaining <= ($website->ssl_expiry_warning_days ?: 14) ? 'expiring_soon' : 'valid');
        } elseif ($httpOnline && ! self::isReservedOrFakeHost($cleanHost)) {
            $sslData = self::inspectSslCertificate($cleanHost);

            if ($sslData['days_remaining'] !== null) {
                $sslDaysRemaining = (int) $sslData['days_remaining'];
                $sslIssuer = $sslData['issuer'] ?: 'Unknown CA';
                $sslExpiresFormatted = $sslData['expires_at'];
                $sslExpiresAt = $sslData['expires_at'] ? Carbon::parse($sslData['expires_at']) : null;
                $sslStatus = $sslDaysRemaining <= 0
                    ? 'expired'
                    : ($sslDaysRemaining <= ($website->ssl_expiry_warning_days ?: 14) ? 'expiring_soon' : 'valid');
            }
        }

        $website->update([
            'server_live_status' => $serverStatus,
            'ssl_status' => $sslStatus,
            'ssl_issuer' => $sslIssuer,
            'ssl_expires_at' => $sslExpiresAt,
            'ssl_days_remaining' => $sslDaysRemaining,
        ]);

        $serverReason = $serverReasons === [] ? null : implode(' | ', array_unique($serverReasons));
        $serverAlertSent = false;

        if ($website->uptime_monitoring_enabled ?? true) {
            if ($serverStatus === 'down' && $previousServerStatus !== 'down') {
                $serverAlertSent = WorkspaceAlertDispatcher::sendWebsiteUptimeAlertEmail(
                    website: $website,
                    isDown: true,
                    reason: (string) $serverReason,
                );
            } elseif ($serverStatus === 'online' && $previousServerStatus === 'down') {
                $serverAlertSent = WorkspaceAlertDispatcher::sendWebsiteUptimeAlertEmail(
                    website: $website,
                    isDown: false,
                    reason: 'Connected server recovered',
                );
            }
        }

        return [
            'server_status' => $serverStatus,
            'server_reason' => $serverReason,
            'server_alert_sent' => $serverAlertSent,
            'ssl_status' => $sslStatus,
            'ssl_days_remaining' => $sslDaysRemaining,
            'ssl_expiry_alert_sent' => self::sendSslExpiryAlertIfDue($website, $sslDaysRemaining, $sslIssuer, $sslExpiresFormatted),
        ];
    }

    /**
     * Sends the SSL expiry reminder at most once per 24 hours while the certificate is inside the warning window.
     */
    protected static function sendSslExpiryAlertIfDue(Website $website, ?int $sslDaysRemaining, ?string $sslIssuer, ?string $sslExpiresFormatted): bool
    {
        $sslWarningThreshold = (int) ($website->ssl_expiry_warning_days ?: 14);

        if ($sslDaysRemaining === null
            || $sslDaysRemaining > $sslWarningThreshold
            || ! ($website->notify_on_ssl_expiry ?? true)
        ) {
            return false;
        }

        $shouldNotifySsl = ($website->last_ssl_expiry_notified_at === null)
            || $website->last_ssl_expiry_notified_at->diffInHours(now()->utc()) >= 24;

        if (! $shouldNotifySsl) {
            return false;
        }

        $sslExpiryAlertSent = WorkspaceAlertDispatcher::sendWebsiteSslExpiryAlertEmail(
            website: $website,
            daysRemaining: $sslDaysRemaining,
            issuer: (string) ($sslIssuer ?: 'Unknown CA'),
            expiresAtFormatted: $sslExpiresFormatted
        );

        if ($sslExpiryAlertSent) {
            $website->update(['last_ssl_expiry_notified_at' => now()->utc()]);
        }

        return $sslExpiryAlertSent;
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

        // 2. SSL Certificate Inspection (if DNS resolves, or fallback to persisted SSL telemetry on $website)
        $sslInfo = [
            'valid' => false,
            'issuer' => $website->ssl_issuer ?: 'N/A',
            'subject' => $cleanHost,
            'expires_at' => $website->ssl_expires_at ? $website->ssl_expires_at->format('M d, Y') : null,
            'days_remaining' => $website->ssl_days_remaining,
            'protocol' => $website->ssl_days_remaining !== null ? 'HTTPS / TLS' : 'None',
            'status_label' => $website->ssl_days_remaining !== null
                ? ($website->ssl_days_remaining > 14
                    ? "🔒 Valid SSL ({$website->ssl_days_remaining} days left)"
                    : ($website->ssl_days_remaining > 0 ? "⚠️ Expiring Soon ({$website->ssl_days_remaining} days left)" : '🔴 SSL Expired'))
                : '❌ No SSL / Domain Unreachable',
            'badge_color' => ($website->ssl_days_remaining ?? -1) > 14 ? '#10b981' : (($website->ssl_days_remaining ?? -1) > 0 ? '#f59e0b' : '#ef4444'),
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
        if ($website->isVerified()) {
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
            $certificate = app(NetworkProbe::class)->sslCertificate($host);

            if ($certificate['expires_at'] === null) {
                return [
                    'valid' => false,
                    'issuer' => 'Unavailable',
                    'subject' => $host,
                    'expires_at' => null,
                    'days_remaining' => null,
                    'protocol' => 'Port 443 Closed',
                    'status_label' => '❌ '.$certificate['error'],
                    'badge_color' => '#ef4444',
                ];
            }

            $issuerOrg = $certificate['issuer'];
            $subjectCn = $certificate['subject'];
            $expiresAt = $certificate['expires_at'];
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
