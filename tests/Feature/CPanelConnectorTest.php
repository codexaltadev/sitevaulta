<?php

namespace Tests\Feature;

use App\Enums\DatabaseDriver;
use App\Enums\FileProtocol;
use App\Models\ServerConnection;
use App\Models\Workspace;
use App\Services\Connectors\CPanelClient;
use App\Services\Connectors\ServerConnectorManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CPanelConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected const BASE = 'https://8.8.8.8:2083';

    protected string $stagingDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->stagingDir = sys_get_temp_dir().'/sv-cpanel-test-'.Str::random(8);
        mkdir($this->stagingDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->stagingDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->stagingDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->stagingDir);
        }

        parent::tearDown();
    }

    protected function makeConnection(array $fileCreds = [], DatabaseDriver $db = DatabaseDriver::CPanelMySql): ServerConnection
    {
        $workspace = Workspace::where('slug', 'demo-agency')->firstOrFail();

        return ServerConnection::create([
            'workspace_id' => $workspace->id,
            'website_id' => $workspace->websites()->first()->id,
            'name' => 'cPanel Shared Hosting',
            'file_protocol' => FileProtocol::CPanelApi,
            'file_credentials' => array_merge([
                'cpanel_url' => '8.8.8.8',
                'username' => 'acme',
                'api_token' => 'TOKEN123',
                'root_path' => '',
            ], $fileCreds),
            'db_driver' => $db,
            'db_use_ssh_tunnel' => false,
            'db_credentials' => ['database' => 'shop'],
        ]);
    }

    protected function fakeCPanel(array $overrides = []): void
    {
        $pngBytes = "\x89PNG\r\n\x1a\n".str_repeat("\x00\xff", 10);

        Http::fake(array_merge([
            self::BASE.'/execute/Fileman/list_files*' => function (Request $request) use ($pngBytes) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);

                return match ($q['dir']) {
                    '/home/acme/public_html' => Http::response(['status' => 1, 'data' => [
                        ['file' => 'index.php', 'fullpath' => '/home/acme/public_html/index.php', 'type' => 'file', 'size' => 18, 'mtime' => 1000],
                        ['file' => 'assets', 'fullpath' => '/home/acme/public_html/assets', 'type' => 'dir', 'size' => 4096, 'mtime' => 1000],
                        ['file' => 'cache', 'fullpath' => '/home/acme/public_html/cache', 'type' => 'dir', 'size' => 4096, 'mtime' => 1000],
                    ]]),
                    '/home/acme/public_html/assets' => Http::response(['status' => 1, 'data' => [
                        ['file' => 'logo.png', 'fullpath' => '/home/acme/public_html/assets/logo.png', 'type' => 'file', 'size' => strlen($pngBytes), 'mtime' => 2000],
                    ]]),
                    default => Http::response(['status' => 0, 'errors' => ['Directory not found']]),
                };
            },
            self::BASE.'/download*' => function (Request $request) use ($pngBytes) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);

                return match ($q['file']) {
                    '/home/acme/public_html/index.php' => Http::response('<?php echo "hi";?>', 200, ['Content-Type' => 'application/octet-stream']),
                    '/home/acme/public_html/assets/logo.png' => Http::response($pngBytes, 200, ['Content-Type' => 'image/png']),
                    default => Http::response('', 404),
                };
            },
            self::BASE.'/execute/Mysql/list_databases' => Http::response(['status' => 1, 'data' => [
                ['database' => 'acme_shop', 'disk_usage' => 524288],
            ]]),
            self::BASE.'/getsqlbackup/acme_shop.sql.gz' => Http::response(gzencode("CREATE TABLE t (id int);\n"), 200, ['Content-Type' => 'application/x-gzip']),
        ], $overrides));
    }

    public function test_normalizes_cpanel_url_and_rejects_plain_http(): void
    {
        $this->assertSame('https://8.8.8.8:2083', CPanelClient::normalizeBaseUrl('8.8.8.8'));
        $this->assertSame('https://8.8.8.8:2087', CPanelClient::normalizeBaseUrl('https://8.8.8.8:2087/'));

        $this->expectException(\RuntimeException::class);
        CPanelClient::normalizeBaseUrl('http://8.8.8.8:2082');
    }

    public function test_connection_test_verifies_token_root_and_database(): void
    {
        $this->fakeCPanel();
        $conn = $this->makeConnection();

        $result = ServerConnectorManager::testConnection($conn);

        $this->assertTrue($result->success, $result->message);
        $this->assertStringContainsString('/home/acme/public_html', $result->message);
        $this->assertStringContainsString('acme_shop', $result->message);
        $this->assertSame(524288, $result->detectedDbSizeBytes);
        $this->assertSame('connected', $conn->fresh()->health_status);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'cpanel acme:TOKEN123'));
    }

    public function test_connection_test_reports_rejected_token(): void
    {
        $this->fakeCPanel([
            self::BASE.'/execute/Fileman/list_files*' => Http::response('Access denied', 401),
        ]);

        $result = ServerConnectorManager::testConnection($this->makeConnection());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('rejected the API token', $result->message);
    }

    public function test_connection_test_reports_missing_database(): void
    {
        $this->fakeCPanel([
            self::BASE.'/execute/Mysql/list_databases' => Http::response(['status' => 1, 'data' => [['database' => 'acme_blog']]]),
        ]);

        $result = ServerConnectorManager::testConnection($this->makeConnection());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('[acme_shop] not found', $result->message);
    }

    public function test_full_extraction_downloads_files_and_database_dump(): void
    {
        $this->fakeCPanel();
        $conn = $this->makeConnection();

        $result = ServerConnectorManager::extractWorkoutToStaging(
            connection: $conn,
            stagingDir: $this->stagingDir,
            includeFiles: true,
            includeDatabase: true,
            excludePaths: ['cache'],
        );

        $this->assertTrue($result->success);
        $this->assertSame(3, $result->sourceFilesCount); // 2 files + 1 DB dump
        $this->assertSame('<?php echo "hi";?>', file_get_contents($this->stagingDir.'/website-files/index.php'));
        $this->assertStringStartsWith("\x89PNG", file_get_contents($this->stagingDir.'/website-files/assets/logo.png'));
        $this->assertSame("CREATE TABLE t (id int);\n", file_get_contents($this->stagingDir.'/database-dump.sql'));
        $this->assertArrayHasKey('assets/logo.png', $result->fileIndexManifest);

        // Excluded directory is never listed
        Http::assertNotSent(fn (Request $r) => str_contains(urldecode($r->url()), 'public_html/cache'));
    }

    public function test_incremental_extraction_skips_unchanged_files(): void
    {
        $this->fakeCPanel();

        $result = ServerConnectorManager::extractWorkoutToStaging(
            connection: $this->makeConnection(),
            stagingDir: $this->stagingDir,
            includeFiles: true,
            includeDatabase: false,
            excludePaths: ['cache'],
            previousManifest: [
                'index.php' => ['size' => 18, 'mtime' => 1000],
                'assets/logo.png' => ['size' => 1, 'mtime' => 1], // changed
            ],
        );

        $this->assertSame(2, $result->sourceFilesCount);
        $this->assertSame(1, $result->changedFilesCount);
        $this->assertFileDoesNotExist($this->stagingDir.'/website-files/index.php');
        $this->assertFileExists($this->stagingDir.'/website-files/assets/logo.png');
    }

    public function test_download_falls_back_to_get_file_content_when_endpoint_returns_login_page(): void
    {
        $this->fakeCPanel([
            self::BASE.'/download*' => Http::response('<html><form id="login_form">cPanel Login</form></html>', 200, ['Content-Type' => 'text/html']),
            self::BASE.'/execute/Fileman/get_file_content*' => Http::response(['status' => 1, 'data' => ['content' => '<?php echo "hi";?>']]),
        ]);

        $client = CPanelClient::fromConnection($this->makeConnection());
        $local = $this->stagingDir.'/index.php';
        $bytes = $client->downloadFile('/home/acme/public_html/index.php', $local, 18);

        $this->assertSame(18, $bytes);
        $this->assertSame('<?php echo "hi";?>', file_get_contents($local));
    }

    public function test_extraction_fails_loudly_instead_of_writing_metadata_only_backup(): void
    {
        $this->fakeCPanel([
            self::BASE.'/execute/Fileman/list_files*' => Http::response('denied', 403),
        ]);

        $this->expectException(\RuntimeException::class);

        ServerConnectorManager::extractWorkoutToStaging(
            connection: $this->makeConnection(),
            stagingDir: $this->stagingDir,
            includeFiles: true,
            includeDatabase: false,
        );
    }

    public function test_sql_backup_falls_back_to_readonly_password_session_when_token_gets_403(): void
    {
        $this->fakeCPanel([
            self::BASE.'/getsqlbackup/acme_shop.sql.gz' => Http::response(
                '<h2>HTTP error 403: Forbidden</h2><p>Token authentication allows access to UAPI or API 2 calls only.</p>',
                403
            ),
            self::BASE.'/login/?login_only=1' => Http::response(
                ['status' => 1, 'security_token' => '/cpsess9876543210'],
                200,
                ['Set-Cookie' => 'cpsession=acme%3asessioncookie123; HttpOnly; Path=/; Secure']
            ),
            self::BASE.'/cpsess9876543210/getsqlbackup/acme_shop.sql.gz' => Http::response(
                gzencode("CREATE TABLE students (id int);\n"),
                200,
                ['Content-Type' => 'application/x-gzip']
            ),
        ]);

        $conn = $this->makeConnection(['cpanel_password' => 'SecretCPanelPass!']);

        $result = ServerConnectorManager::extractWorkoutToStaging(
            connection: $conn,
            stagingDir: $this->stagingDir,
            includeFiles: false,
            includeDatabase: true,
        );

        $this->assertTrue($result->success);
        $this->assertSame("CREATE TABLE students (id int);\n", file_get_contents($this->stagingDir.'/database-dump.sql'));
    }
}
