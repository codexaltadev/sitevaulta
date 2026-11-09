<?php

namespace App\Services\Security;

use App\Enums\FileProtocol;
use App\Models\ServerConnection;
use App\Models\Website;
use App\Services\Connectors\CPanelClient;
use App\Services\Notifications\WorkspaceAlertDispatcher;
use App\Services\Verification\WebsiteUptimeMonitorService;
use Illuminate\Support\Facades\Http;
use phpseclib3\Net\SFTP;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 100% READ-ONLY & NON-DESTRUCTIVE Website Malware, Web-Shell & Security Shield Scanner.
 *
 * ARCHITECTURAL GUARANTEE:
 * - This service NEVER deletes, modifies, renames, quarantines, or writes any file or data on the live server.
 * - It strictly reads and reports detected file locations, line numbers, and matched signatures for owner review.
 */
class WebsiteSecurityScannerService
{
    /**
     * Known Web-Shell & Backdoor filenames.
     *
     * @var array<int, string>
     */
    protected const KNOWN_WEBSHELL_FILENAMES = [
        'c99.php',
        'c99shell.php',
        'r57.php',
        'r57shell.php',
        'wso.php',
        'wso2.php',
        'b374k.php',
        'alfa.php',
        'alfashell.php',
        'shell.php',
        'webshell.php',
        'backdoor.php',
        'minishell.php',
        'priv8.php',
        'bypass.php',
        'indoxploit.php',
        'filemanager_shell.php',
        'up.php',
        ' uploader.php',
    ];

    /**
     * High-confidence Malware, Web-Shell & Backdoor code signatures.
     *
     * @var array<int, array{category: string, severity: string, label: string, pattern: string, recommendation: string}>
     */
    protected const CODE_SIGNATURES = [
        [
            'category' => 'PHP Web-Shell / Backdoor',
            'severity' => 'CRITICAL',
            'label' => 'Known Web-Shell Signature (FilesMan / WSO / c99 / r57 / b374k)',
            'pattern' => '/\b(FilesMan|WSO_VERSION|c99sh_surl|r57shell|b374k|Alfa_Shell|Indoxploit)\b/i',
            'recommendation' => 'Inspect this file on your server immediately. It matches a known PHP hacker web-shell.',
        ],
        [
            'category' => 'Obfuscated Malware Script',
            'severity' => 'CRITICAL',
            'label' => 'Obfuscated eval(base64_decode / gzinflate / str_rot13) Payload',
            'pattern' => '/\beval\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i',
            'recommendation' => 'Review this line on your server. Legitimate scripts rarely wrap base64_decode or gzinflate inside eval().',
        ],
        [
            'category' => 'PHP Web-Shell / Backdoor',
            'severity' => 'CRITICAL',
            'label' => 'Direct Remote Command Execution via Superglobal ($_GET / $_POST / $_REQUEST)',
            'pattern' => '/\b(shell_exec|passthru|system|proc_open|popen|exec)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)\b/i',
            'recommendation' => 'Critical backdoor risk: user input from $_GET/$_POST is passed directly to OS shell execution.',
        ],
        [
            'category' => 'PHP Web-Shell / Backdoor',
            'severity' => 'CRITICAL',
            'label' => 'Dynamic Code Execution via assert() or create_function() on Superglobal',
            'pattern' => '/\b(assert|create_function)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)\b/i',
            'recommendation' => 'Inspect this file on your server. Attackers use assert($_POST[...]) as a stealthy PHP backdoor.',
        ],
        [
            'category' => 'Obfuscated Malware Script',
            'severity' => 'HIGH',
            'label' => 'Hex-Encoded eval/base64 Backdoor Dropper (\\x65\\x76\\x61\\x6c)',
            'pattern' => '/(\\\\x65\\\\x76\\\\x61\\\\x6c|\\\\x62\\\\x61\\\\x73\\\\x65\\\\x36\\\\x34)/i',
            'recommendation' => 'Contains hex-encoded "eval" or "base64" strings commonly injected at the top of compromised PHP files.',
        ],
        [
            'category' => 'Malicious .htaccess Injection',
            'severity' => 'CRITICAL',
            'label' => 'Suspicious auto_prepend_file Malware Hook in .htaccess',
            'pattern' => '/php_value\s+auto_prepend_file\s+.*(\/tmp\/|\/uploads\/|\.jpg|\.png|\.ico)/i',
            'recommendation' => 'Check your .htaccess file. Attackers use auto_prepend_file to execute hidden PHP malware inside image/temp files.',
        ],
    ];

    /**
     * Runs a comprehensive 100% Read-Only Malware, Web-Shell & Security Shield Scan for a Website.
     * Never modifies or deletes any file on the live server.
     *
     * @param  string|null  $localStagedFilesDir  Optional path to already-staged backup files for deep offline inspection
     * @return array{status: string, threats_count: int, critical_count: int, warnings_count: int, findings: array<int, array<string, mixed>>, scanned_at: string, email_notified: bool}
     */
    public static function scanWebsiteAndServer(
        Website $website,
        ?string $localStagedFilesDir = null,
        bool $sendAlertIfThreatsFound = true
    ): array {
        $website->loadMissing(['workspace.owner', 'serverConnections']);
        $findings = [];

        // 1. Live HTTP/HTTPS External Security & Public File Exposure Check (Read-Only GET)
        $httpFindings = self::scanLiveHttpSecurityAndExposures($website);
        $findings = array_merge($findings, $httpFindings);

        // 2. If local staged backup files are provided (e.g. during a Backup Run), scan all extracted files locally
        if ($localStagedFilesDir && is_dir($localStagedFilesDir)) {
            $remoteRoot = '/public_html';
            $firstConn = $website->serverConnections->first();
            if ($firstConn) {
                $endpoint = WebsiteUptimeMonitorService::resolveServerEndpoint($firstConn, $website);
                $remoteRoot = $endpoint['root_path'] ?: '/public_html';
            }

            $stagedFindings = self::scanLocalStagedFiles($localStagedFilesDir, $remoteRoot);
            $findings = array_merge($findings, $stagedFindings);
        } else {
            // 3. Otherwise, perform a 100% Read-Only Remote Server Directory & File Inspection
            $remoteFindings = self::scanRemoteServerReadOnly($website);
            $findings = array_merge($findings, $remoteFindings);
        }

        // Deduplicate findings by location + label
        $uniqueFindings = [];
        foreach ($findings as $finding) {
            $key = ($finding['location'] ?? '').'|'.($finding['label'] ?? '');
            $uniqueFindings[$key] = $finding;
        }
        $findings = array_values($uniqueFindings);

        $criticalOrHighCount = collect($findings)
            ->whereIn('severity', ['CRITICAL', 'HIGH'])
            ->count();

        $warningsCount = collect($findings)
            ->whereIn('severity', ['MEDIUM', 'LOW'])
            ->count();

        $totalThreats = $criticalOrHighCount;

        $status = match (true) {
            $criticalOrHighCount > 0 => 'infected',
            $warningsCount > 0 => 'warning',
            default => 'clean',
        };

        $scannedAt = now()->utc();

        $reportPayload = [
            'scan_mode' => '100% Read-Only Diagnostic (Zero Server Modification)',
            'scanned_at' => $scannedAt->toIso8601String(),
            'status' => $status,
            'critical_or_high_count' => $criticalOrHighCount,
            'warnings_count' => $warningsCount,
            'findings' => $findings,
        ];

        $website->update([
            'security_scan_status' => $status,
            'security_threats_count' => $criticalOrHighCount,
            'security_scan_report' => $reportPayload,
            'last_security_scan_at' => $scannedAt,
        ]);

        $emailNotified = false;
        if ($sendAlertIfThreatsFound && $criticalOrHighCount > 0 && ($website->notify_on_malware_detected ?? true)) {
            $emailNotified = WorkspaceAlertDispatcher::sendWebsiteMalwareAlertEmail($website, $reportPayload);
        }

        AuditLogService::log(
            event: 'website.security_scan.completed',
            auditable: $website,
            metadata: [
                'domain' => $website->domain,
                'status' => $status,
                'critical_or_high_count' => $criticalOrHighCount,
                'warnings_count' => $warningsCount,
                'email_notified' => $emailNotified,
            ],
            workspaceId: $website->workspace_id
        );

        return [
            'status' => $status,
            'threats_count' => $criticalOrHighCount,
            'critical_count' => $criticalOrHighCount,
            'warnings_count' => $warningsCount,
            'findings' => $findings,
            'scanned_at' => $scannedAt->toIso8601String(),
            'email_notified' => $emailNotified,
        ];
    }

    /**
     * Scans local staged files (e.g. during a backup run or in tests) in 100% Read-Only mode.
     * Reports exact server path, line number, and matched malware/web-shell signature.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function scanLocalStagedFiles(string $localDirectory, string $remoteRootPath = '/public_html'): array
    {
        if (! is_dir($localDirectory)) {
            return [];
        }

        $findings = [];
        $remoteRootPath = rtrim($remoteRootPath, '/');
        $normalizedLocalBase = rtrim(str_replace('\\', '/', $localDirectory), '/');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($localDirectory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if (! $fileInfo->isFile()) {
                continue;
            }

            $fullLocalPath = str_replace('\\', '/', $fileInfo->getPathname());
            $relativePath = ltrim(substr($fullLocalPath, strlen($normalizedLocalBase)), '/');
            $serverFilePath = "{$remoteRootPath}/{$relativePath}";
            $filenameLower = strtolower($fileInfo->getFilename());

            // 1. Check Known Web-Shell Filenames
            if (in_array($filenameLower, self::KNOWN_WEBSHELL_FILENAMES, true)) {
                $findings[] = [
                    'severity' => 'CRITICAL',
                    'category' => 'PHP Web-Shell / Backdoor',
                    'label' => "Known Web-Shell Filename Detected ({$fileInfo->getFilename()})",
                    'location' => $serverFilePath,
                    'line' => 1,
                    'evidence' => "Filename [{$fileInfo->getFilename()}] matches known hacker web-shell signature.",
                    'recommendation' => 'Read-Only Alert: Log in to your server and inspect this file manually.',
                ];
            }

            // 2. Check Executable PHP scripts hidden inside Upload / Media folders
            if (self::isUploadDirectory($relativePath) && preg_match('/\.(php|phtml|php5|php7|phar|cgi|pl|sh)$/i', $filenameLower)) {
                $findings[] = [
                    'severity' => 'HIGH',
                    'category' => 'Executable Script in Uploads Directory',
                    'label' => 'Executable Script Found Inside Media/Uploads Folder',
                    'location' => $serverFilePath,
                    'line' => 1,
                    'evidence' => "Executable script [{$fileInfo->getFilename()}] is located inside an uploads/media directory.",
                    'recommendation' => 'Upload directories should only contain media/documents, not executable PHP scripts.',
                ];
            }

            // 3. Inspect file contents for Malware / Web-Shell signatures (up to 1 MB per scannable file)
            if ($fileInfo->getSize() > 0 && $fileInfo->getSize() <= 1024 * 1024 && self::isScannableCodeFile($filenameLower)) {
                $content = @file_get_contents($fileInfo->getPathname());
                if (is_string($content) && $content !== '') {
                    $findings = array_merge(
                        $findings,
                        self::scanFileContentString($content, $serverFilePath)
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * Scans a file's text content against high-confidence malware & web-shell signatures.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function scanFileContentString(string $content, string $serverFilePath): array
    {
        $findings = [];
        $lines = explode("\n", $content);

        foreach (self::CODE_SIGNATURES as $sig) {
            if (preg_match($sig['pattern'], $content)) {
                $matchedLineNum = 1;
                $matchedSnippet = '';

                foreach ($lines as $idx => $lineText) {
                    if (preg_match($sig['pattern'], $lineText)) {
                        $matchedLineNum = $idx + 1;
                        $matchedSnippet = trim(mb_substr($lineText, 0, 140));
                        break;
                    }
                }

                $findings[] = [
                    'severity' => $sig['severity'],
                    'category' => $sig['category'],
                    'label' => $sig['label'],
                    'location' => $serverFilePath,
                    'line' => $matchedLineNum,
                    'evidence' => "Line {$matchedLineNum}: {$matchedSnippet}",
                    'recommendation' => $sig['recommendation'],
                ];
            }
        }

        return $findings;
    }

    /**
     * Performs a 100% Read-Only inspection of the live server via cPanel API or SFTP
     * without modifying or deleting any file on the server.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function scanRemoteServerReadOnly(Website $website): array
    {
        $findings = [];

        foreach ($website->serverConnections as $conn) {
            /** @var ServerConnection $conn */
            $creds = $conn->file_credentials ?? [];
            if (($creds['simulation_mode'] ?? false) === true) {
                continue;
            }

            $protocol = (string) ($conn->file_protocol?->value ?? $conn->file_protocol ?? '');

            try {
                if ($protocol === FileProtocol::CPanelApi->value || $protocol === 'cpanel_api') {
                    $findings = array_merge($findings, self::scanCPanelServerReadOnly($conn, $website));
                } elseif ($protocol === FileProtocol::SshSftp->value || $protocol === 'ssh_sftp') {
                    $findings = array_merge($findings, self::scanSftpServerReadOnly($conn, $website));
                }
            } catch (\Throwable) {
                // Non-blocking if remote server is currently unreachable
            }
        }

        return $findings;
    }

    /**
     * 100% Read-Only cPanel Fileman inspection (checks root & upload directories).
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function scanCPanelServerReadOnly(ServerConnection $conn, Website $website): array
    {
        $findings = [];
        $client = CPanelClient::fromConnection($conn, 15);
        $endpoint = WebsiteUptimeMonitorService::resolveServerEndpoint($conn, $website);
        $rootPath = rtrim($endpoint['root_path'], '/');

        $dirsToInspect = [
            $rootPath,
            "{$rootPath}/uploads",
            "{$rootPath}/wp-content/uploads",
            "{$rootPath}/storage/app/public",
        ];

        foreach ($dirsToInspect as $dirPath) {
            try {
                $entries = $client->listDirectory($dirPath);
            } catch (\Throwable) {
                continue;
            }

            $isUploadDir = ($dirPath !== $rootPath);

            foreach ($entries as $entry) {
                if (($entry['type'] ?? '') !== 'file') {
                    continue;
                }

                $name = (string) ($entry['name'] ?? '');
                $nameLower = strtolower($name);
                $fullRemotePath = "{$dirPath}/{$name}";

                if (in_array($nameLower, self::KNOWN_WEBSHELL_FILENAMES, true)) {
                    $findings[] = [
                        'severity' => 'CRITICAL',
                        'category' => 'PHP Web-Shell / Backdoor',
                        'label' => "Known Web-Shell Filename Detected ({$name})",
                        'location' => $fullRemotePath,
                        'line' => 1,
                        'evidence' => "File [{$fullRemotePath}] on server [{$conn->name}] matches a known web-shell filename.",
                        'recommendation' => 'Read-Only Alert: Open cPanel File Manager and inspect this file manually.',
                    ];
                }

                if ($isUploadDir && preg_match('/\.(php|phtml|php5|php7|phar|cgi|pl|sh)$/i', $nameLower)) {
                    $findings[] = [
                        'severity' => 'HIGH',
                        'category' => 'Executable Script in Uploads Directory',
                        'label' => "Executable Script [{$name}] Found in Uploads Directory",
                        'location' => $fullRemotePath,
                        'line' => 1,
                        'evidence' => "Executable script [{$fullRemotePath}] found inside media/uploads folder.",
                        'recommendation' => 'Read-Only Alert: Inspect this PHP script in your uploads directory via cPanel File Manager.',
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * 100% Read-Only SFTP directory inspection.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function scanSftpServerReadOnly(ServerConnection $conn, Website $website): array
    {
        $findings = [];
        $creds = $conn->file_credentials ?? [];
        $host = trim((string) ($creds['host'] ?? ''));
        $port = (int) ($creds['port'] ?? 22);
        $username = trim((string) ($creds['username'] ?? ''));
        $rootPath = rtrim((string) ($creds['root_path'] ?? '/var/www/html'), '/');

        if ($host === '' || WebsiteUptimeMonitorService::isReservedOrFakeHost($host)) {
            return [];
        }

        $sftp = new SFTP($host, $port, 8);
        $password = (string) ($creds['password'] ?? '');
        if (! $sftp->login($username, $password)) {
            return [];
        }

        $dirsToInspect = [
            $rootPath,
            "{$rootPath}/uploads",
            "{$rootPath}/wp-content/uploads",
            "{$rootPath}/public/uploads",
        ];

        foreach ($dirsToInspect as $dirPath) {
            $rawList = @$sftp->rawlist($dirPath);
            if (! is_array($rawList)) {
                continue;
            }

            $isUploadDir = ($dirPath !== $rootPath);

            foreach ($rawList as $name => $stat) {
                if ($name === '.' || $name === '..' || (($stat['type'] ?? 0) === NET_SFTP_TYPE_DIRECTORY)) {
                    continue;
                }

                $nameLower = strtolower((string) $name);
                $fullRemotePath = "{$dirPath}/{$name}";

                if (in_array($nameLower, self::KNOWN_WEBSHELL_FILENAMES, true)) {
                    $findings[] = [
                        'severity' => 'CRITICAL',
                        'category' => 'PHP Web-Shell / Backdoor',
                        'label' => "Known Web-Shell Filename Detected ({$name})",
                        'location' => $fullRemotePath,
                        'line' => 1,
                        'evidence' => "File [{$fullRemotePath}] on SFTP server [{$host}] matches a known web-shell filename.",
                        'recommendation' => 'Read-Only Alert: Connect to your server via SSH/SFTP and inspect this file manually.',
                    ];
                }

                if ($isUploadDir && preg_match('/\.(php|phtml|php5|php7|phar|cgi|pl|sh)$/i', $nameLower)) {
                    $findings[] = [
                        'severity' => 'HIGH',
                        'category' => 'Executable Script in Uploads Directory',
                        'label' => "Executable Script [{$name}] Found in Uploads Directory",
                        'location' => $fullRemotePath,
                        'line' => 1,
                        'evidence' => "Executable script [{$fullRemotePath}] found inside uploads directory.",
                        'recommendation' => 'Read-Only Alert: Review why an executable script is inside your uploads folder.',
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * Probes the live website over HTTP/HTTPS (100% Read-Only GET) for:
     * - Publicly exposed .env or .git/config files
     * - Frontend injected malicious scripts / hidden iframes
     * - Missing HTTP Security Shield headers
     *
     * @return array<int, array<string, mixed>>
     */
    public static function scanLiveHttpSecurityAndExposures(Website $website): array
    {
        $findings = [];
        $cleanHost = WebsiteUptimeMonitorService::extractCleanHostname($website);

        if ($cleanHost === '' || WebsiteUptimeMonitorService::isReservedOrFakeHost($cleanHost)) {
            return [];
        }

        $resolvedIps = @gethostbynamel($cleanHost);
        if (empty($resolvedIps)) {
            return [];
        }

        $baseUrl = str_contains((string) $website->url, '://')
            ? rtrim((string) $website->url, '/')
            : "https://{$cleanHost}";

        try {
            // 1. Check Homepage HTML & Security Shield Headers
            $homeResp = Http::timeout(6)
                ->connectTimeout(4)
                ->withoutVerifying()
                ->withHeaders(['User-Agent' => 'SiteVaulta-SecurityScanner/1.0'])
                ->get($baseUrl);

            if ($homeResp->successful()) {
                $body = $homeResp->body();

                // Check for hidden malicious iframe or crypto-miner script in public HTML
                if (preg_match('/<iframe[^>]+(display\s*:\s*none|visibility\s*:\s*hidden|width\s*=\s*["\']0["\'])[^>]+src\s*=\s*["\']https?:\/\/(?!'.preg_quote($cleanHost, '/').')/i', $body)) {
                    $findings[] = [
                        'severity' => 'HIGH',
                        'category' => 'Frontend Malicious Script / Hidden Iframe',
                        'label' => 'Suspicious Hidden External Iframe Detected on Homepage',
                        'location' => "{$baseUrl}/ (Homepage HTML)",
                        'line' => 1,
                        'evidence' => 'Detected hidden <iframe> loading an external third-party URL with display:none or width=0.',
                        'recommendation' => 'Inspect your website header/footer templates for unauthorized iframe injections.',
                    ];
                }

                // Check Security Shield Headers (Informational / Medium Warning)
                $missingHeaders = [];
                if (! $homeResp->header('X-Frame-Options') && ! str_contains(strtolower((string) $homeResp->header('Content-Security-Policy')), 'frame-ancestors')) {
                    $missingHeaders[] = 'X-Frame-Options';
                }
                if (! $homeResp->header('X-Content-Type-Options')) {
                    $missingHeaders[] = 'X-Content-Type-Options';
                }

                if (! empty($missingHeaders)) {
                    $findings[] = [
                        'severity' => 'LOW',
                        'category' => 'Missing HTTP Security Shield Header',
                        'label' => 'Missing Recommended Security Headers ('.implode(', ', $missingHeaders).')',
                        'location' => "{$baseUrl} (HTTP Response Headers)",
                        'line' => 0,
                        'evidence' => 'Missing headers: '.implode(', ', $missingHeaders),
                        'recommendation' => 'Add X-Frame-Options: SAMEORIGIN and X-Content-Type-Options: nosniff to your web server or .htaccess.',
                    ];
                }
            }

            // 2. Check if /.env is publicly exposed
            $envResp = Http::timeout(4)
                ->connectTimeout(3)
                ->withoutVerifying()
                ->withHeaders(['User-Agent' => 'SiteVaulta-SecurityScanner/1.0'])
                ->get("{$baseUrl}/.env");

            if ($envResp->status() === 200 && preg_match('/^(APP_KEY|DB_PASSWORD|DB_HOST|AWS_SECRET_ACCESS_KEY)\s*=/m', $envResp->body())) {
                $findings[] = [
                    'severity' => 'CRITICAL',
                    'category' => 'Publicly Exposed Sensitive File',
                    'label' => 'CRITICAL: /.env Configuration File is Publicly Accessible!',
                    'location' => "{$baseUrl}/.env",
                    'line' => 1,
                    'evidence' => 'HTTP 200 returned sensitive environment variables (DB_PASSWORD / APP_KEY) at /.env.',
                    'recommendation' => 'Immediately block public access to /.env in your .htaccess or Nginx config and rotate your credentials.',
                ];
            }

            // 3. Check if /.git/config is publicly exposed
            $gitResp = Http::timeout(4)
                ->connectTimeout(3)
                ->withoutVerifying()
                ->withHeaders(['User-Agent' => 'SiteVaulta-SecurityScanner/1.0'])
                ->get("{$baseUrl}/.git/config");

            if ($gitResp->status() === 200 && str_contains($gitResp->body(), '[core]')) {
                $findings[] = [
                    'severity' => 'CRITICAL',
                    'category' => 'Publicly Exposed Sensitive File',
                    'label' => 'CRITICAL: /.git/config Repository Metadata is Publicly Accessible!',
                    'location' => "{$baseUrl}/.git/config",
                    'line' => 1,
                    'evidence' => 'HTTP 200 returned Git repository configuration ([core]) at /.git/config.',
                    'recommendation' => 'Deny public HTTP access to the /.git directory on your server immediately.',
                ];
            }
        } catch (\Throwable) {
            // Non-blocking if HTTP checks time out
        }

        return $findings;
    }

    protected static function isUploadDirectory(string $relativePath): bool
    {
        $normalized = '/'.strtolower(trim($relativePath, '/')).'/';

        return str_contains($normalized, '/uploads/')
            || str_contains($normalized, '/wp-content/uploads/')
            || str_contains($normalized, '/storage/app/public/');
    }

    protected static function isScannableCodeFile(string $filenameLower): bool
    {
        return (bool) preg_match('/\.(php|phtml|php5|php7|phar|inc|htaccess|js|html|htm)$/i', $filenameLower);
    }
}

