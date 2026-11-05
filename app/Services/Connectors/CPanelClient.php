<?php

namespace App\Services\Connectors;

use App\Models\ServerConnection;
use App\Services\Security\SsrfValidator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin cPanel UAPI client authenticated with an API token
 * (Header: "Authorization: cpanel {user}:{token}").
 *
 * Used for shared hosting where SSH is not available:
 *  - Fileman/list_files  → directory walk (size + mtime for incremental diff)
 *  - /download           → binary-safe file download (fallback: Fileman/get_file_content)
 *  - Mysql/list_databases → DB existence + size check
 *  - /getsqlbackup       → gzip SQL dump of a cPanel-managed MySQL database
 */
class CPanelClient
{
    public const DEFAULT_PORT = 2083;

    protected string $baseUrl;

    protected ?array $cachedWebSession = null;

    protected bool $tokenBlockedOnStreaming = false;

    public function __construct(
        string $cpanelUrl,
        protected string $username,
        protected string $apiToken,
        protected int $timeout = 30,
        protected string $cpanelPassword = '',
    ) {
        if ($this->username === '' || $this->apiToken === '') {
            throw new RuntimeException('cPanel username and API token are required.');
        }

        $this->baseUrl = self::normalizeBaseUrl($cpanelUrl);
        SsrfValidator::assertSafeHost($this->baseUrl);
    }

    public static function fromConnection(ServerConnection $connection, int $timeout = 30): self
    {
        $creds = $connection->file_credentials ?? [];

        return new self(
            (string) ($creds['cpanel_url'] ?? ''),
            trim((string) ($creds['username'] ?? '')),
            trim((string) ($creds['api_token'] ?? '')),
            $timeout,
            (string) ($creds['cpanel_password'] ?? $creds['password'] ?? ''),
        );
    }

    /**
     * Accepts "server.example.com", "server.example.com:2083" or "https://server.example.com:2083/".
     * Only HTTPS is allowed — the API token must never travel in plain text.
     */
    public static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('cPanel Host URL is required (e.g. https://server.example.com:2083).');
        }

        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if (! $parts || empty($parts['host'])) {
            throw new RuntimeException("Invalid cPanel Host URL [{$url}].");
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('cPanel Host URL must use https:// (port 2083) so the API token is encrypted in transit.');
        }

        $port = (int) ($parts['port'] ?? self::DEFAULT_PORT);

        return "https://{$parts['host']}:{$port}";
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function defaultRootPath(): string
    {
        return "/home/{$this->username}/public_html";
    }

    /**
     * Call a UAPI function and return its `data` payload.
     */
    public function uapi(string $module, string $function, array $params = []): mixed
    {
        $response = $this->request()->get("{$this->baseUrl}/execute/{$module}/{$function}", $params);

        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException("cPanel rejected the API token for account [{$this->username}] (HTTP {$response->status()}).");
        }

        if (! $response->successful()) {
            throw new RuntimeException("cPanel UAPI {$module}/{$function} returned HTTP {$response->status()}.");
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException("cPanel UAPI {$module}/{$function} returned a non-JSON response (check the host URL and port 2083).");
        }

        if ((int) ($json['status'] ?? 0) !== 1) {
            $errors = implode('; ', array_filter((array) ($json['errors'] ?? []))) ?: 'unknown error';

            throw new RuntimeException("cPanel UAPI {$module}/{$function} failed: {$errors}");
        }

        return $json['data'] ?? null;
    }

    /**
     * @return array<int, array{name: string, fullpath: string, type: string, size: int, mtime: int}>
     */
    public function listDirectory(string $dir): array
    {
        $data = $this->uapi('Fileman', 'list_files', [
            'dir' => $dir,
            'show_hidden' => 1,
            'include_mime' => 0,
            'include_permissions' => 0,
            'check_for_leaf_directories' => 0,
        ]);

        $entries = [];
        foreach ((array) $data as $item) {
            $name = (string) ($item['file'] ?? '');
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }

            $entries[] = [
                'name' => $name,
                'fullpath' => (string) ($item['fullpath'] ?? rtrim($dir, '/').'/'.$name),
                'type' => (string) ($item['type'] ?? 'file'),
                'size' => (int) ($item['size'] ?? 0),
                'mtime' => (int) ($item['mtime'] ?? 0),
            ];
        }

        return $entries;
    }

    public function directoryExists(string $dir): bool
    {
        try {
            $this->listDirectory($dir);

            return true;
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'rejected the API token')) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * Download a remote file to $localPath. Tries the binary-safe /download endpoint first,
     * verifies the byte count against the size reported by Fileman/list_files, and falls back
     * to Fileman/get_file_content (text files) if the endpoint is unavailable.
     */
    public function downloadFile(string $remotePath, string $localPath, ?int $expectedSize = null): int
    {
        if (! $this->tokenBlockedOnStreaming) {
            $response = $this->request()
                ->withOptions(['sink' => $localPath])
                ->get("{$this->baseUrl}/download", [
                    'skipencode' => 1,
                    'file' => $remotePath,
                ]);

            if ($response->successful() && ! $this->looksLikeLoginPage($response, $localPath, $expectedSize)) {
                clearstatcache(true, $localPath);
                $bytes = (int) @filesize($localPath);

                if ($expectedSize === null || $bytes === $expectedSize) {
                    return $bytes;
                }
            }

            if ($response->status() === 403 && $this->cpanelPassword !== '') {
                $this->tokenBlockedOnStreaming = true;
            }
        }

        // If API token is blocked on /download (HTTP 403) and cPanel password is provided, try read-only session download
        if ($this->cpanelPassword !== '' && $this->downloadViaPasswordSession('/download', [
            'skipencode' => 1,
            'file' => $remotePath,
        ], $localPath, $expectedSize)) {
            clearstatcache(true, $localPath);

            return (int) @filesize($localPath);
        }

        // Fallback: 100% Read-Only UAPI get_file_content (returns the file body inside JSON)
        $data = $this->uapi('Fileman', 'get_file_content', [
            'dir' => dirname($remotePath),
            'file' => basename($remotePath),
            'from_charset' => '_DETECT_',
            'to_charset' => '_LOCALE_',
        ]);

        $content = (string) ($data['content'] ?? '');
        file_put_contents($localPath, $content);
        $bytes = strlen($content);

        if ($expectedSize !== null && $bytes !== $expectedSize) {
            @unlink($localPath);

            throw new RuntimeException(
                "cPanel download of [{$remotePath}] is incomplete ({$bytes} of {$expectedSize} bytes)."
            );
        }

        return $bytes;
    }

    /**
     * @return array<int, array{database: string, disk_usage: int}>
     */
    public function listDatabases(): array
    {
        $data = $this->uapi('Mysql', 'list_databases');

        return collect((array) $data)
            ->map(fn ($db) => [
                'database' => (string) ($db['database'] ?? ''),
                'disk_usage' => (int) ($db['disk_usage'] ?? 0),
            ])
            ->filter(fn ($db) => $db['database'] !== '')
            ->values()
            ->all();
    }

    /**
     * Download a full SQL dump of a cPanel-managed MySQL database (the same
     * endpoint used by cPanel » Backup » "Download a MySQL Database Backup").
     */
    public function dumpDatabase(string $database, string $targetSqlPath): int
    {
        $gzPath = $targetSqlPath.'.gz';
        $endpointPath = '/getsqlbackup/'.rawurlencode($database).'.sql.gz';

        $response = null;
        if (! $this->tokenBlockedOnStreaming) {
            $response = $this->request()
                ->timeout(max($this->timeout, 600))
                ->withOptions(['sink' => $gzPath])
                ->get("{$this->baseUrl}{$endpointPath}");
        }

        if (($response === null || ! $response->successful()) && $this->cpanelPassword !== '') {
            $this->tokenBlockedOnStreaming = true;
            $this->downloadViaPasswordSession($endpointPath, [], $gzPath);
        } elseif ($response !== null && ! $response->successful()) {
            $body = (string) @file_get_contents($gzPath, false, null, 0, 2048);
            @unlink($gzPath);

            if ($response->status() === 403 && str_contains($body, 'UAPI or API 2 calls only')) {
                throw new RuntimeException(
                    "cPanel SQL backup for [{$database}] returned HTTP 403 because cPanel blocks API Tokens on /getsqlbackup. "
                    ."Please edit your Server Connection and enter your 'cPanel Account Password' to stream the SQL backup in 100% read-only mode."
                );
            }

            throw new RuntimeException("cPanel SQL backup for [{$database}] returned HTTP {$response->status()}.");
        }

        $magic = (string) @file_get_contents($gzPath, false, null, 0, 2);
        if ($magic !== "\x1f\x8b") {
            @unlink($gzPath);

            throw new RuntimeException("cPanel SQL backup for [{$database}] did not return a gzip archive (verify your cPanel Account Password and database name).");
        }

        $in = gzopen($gzPath, 'rb');
        $out = fopen($targetSqlPath, 'wb');
        if (! $in || ! $out) {
            throw new RuntimeException('Could not open staging files to decompress the SQL dump.');
        }

        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, 1024 * 512));
        }
        gzclose($in);
        fclose($out);
        @unlink($gzPath);

        clearstatcache(true, $targetSqlPath);

        return (int) filesize($targetSqlPath);
    }

    /**
     * Authenticate read-only cPanel streaming endpoints (/getsqlbackup and /download)
     * using the cPanel account password via HTTP Basic Auth or cpsess login token.
     * Never writes or deletes any file on the remote cPanel server.
     */
    protected function downloadViaPasswordSession(
        string $endpointPath,
        array $query,
        string $sinkPath,
        ?int $expectedSize = null
    ): bool {
        if ($this->cpanelPassword === '') {
            return false;
        }

        // 1. Establish and reuse a read-only cpsess security token via /login/?login_only=1
        $session = $this->establishWebSession();
        if ($session) {
            $sessionRes = Http::timeout(max($this->timeout, 600))
                ->connectTimeout(10)
                ->withHeaders(['Cookie' => $session['cookie']])
                ->withOptions([
                    'allow_redirects' => false,
                    'sink' => $sinkPath,
                ])
                ->get("{$this->baseUrl}{$session['security_token']}{$endpointPath}", $query);

            if ($sessionRes->successful() && ! $this->looksLikeLoginPage($sessionRes, $sinkPath, $expectedSize)) {
                return true;
            }
        }

        // 2. Fallback to direct HTTP Basic Auth
        $basicRes = Http::timeout(max($this->timeout, 600))
            ->connectTimeout(10)
            ->withBasicAuth($this->username, $this->cpanelPassword)
            ->withOptions([
                'allow_redirects' => false,
                'sink' => $sinkPath,
            ])
            ->get("{$this->baseUrl}{$endpointPath}", $query);

        return $basicRes->successful() && ! $this->looksLikeLoginPage($basicRes, $sinkPath, $expectedSize);
    }

    /**
     * @return array{security_token: string, cookie: string}|null
     */
    protected function establishWebSession(): ?array
    {
        if ($this->cachedWebSession !== null) {
            return $this->cachedWebSession;
        }

        $loginRes = Http::timeout(15)
            ->connectTimeout(10)
            ->asForm()
            ->withOptions(['allow_redirects' => false])
            ->post("{$this->baseUrl}/login/?login_only=1", [
                'user' => $this->username,
                'pass' => $this->cpanelPassword,
            ]);

        $json = $loginRes->json();
        $securityToken = is_array($json) ? (string) ($json['security_token'] ?? '') : '';
        if ($securityToken === '' || ! str_starts_with($securityToken, '/cpsess')) {
            return null;
        }

        $cookies = [];
        foreach ($loginRes->headers()['Set-Cookie'] ?? $loginRes->headers()['set-cookie'] ?? [] as $setCookie) {
            $pair = explode(';', (string) $setCookie, 2)[0] ?? '';
            if ($pair !== '') {
                $cookies[] = trim($pair);
            }
        }

        return $this->cachedWebSession = [
            'security_token' => $securityToken,
            'cookie' => implode('; ', $cookies),
        ];
    }

    protected function request(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->connectTimeout(10)
            ->withHeaders(['Authorization' => "cpanel {$this->username}:{$this->apiToken}"])
            // Never follow redirects: prevents SSRF bypass and token leaks to other hosts.
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * cPanel answers unauthenticated /download requests with its HTML login page (HTTP 200).
     */
    protected function looksLikeLoginPage(Response $response, string $localPath, ?int $expectedSize): bool
    {
        $contentType = strtolower($response->header('Content-Type'));
        if (! str_contains($contentType, 'text/html')) {
            return false;
        }

        clearstatcache(true, $localPath);
        if ($expectedSize !== null && (int) @filesize($localPath) === $expectedSize) {
            return false; // a genuine .html file of the exact expected size
        }

        $head = (string) @file_get_contents($localPath, false, null, 0, 4096);

        return str_contains($head, 'login_form') || str_contains($head, 'cPanel Login') || str_contains($head, 'id="login');
    }
}
