<?php

namespace Tests\Feature;

use App\Models\BackupArtifact;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\ServerConnection;
use App\Models\StorageConnection;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Backup\ArchivePackagerService;
use App\Services\Restore\DownloadTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupEncryptionDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_native_aes_256_zip_encryption_and_on_demand_decrypted_download(): void
    {
        $workspace = Workspace::where('slug', 'demo-agency')->firstOrFail();
        $website = Website::where('workspace_id', $workspace->id)->firstOrFail();
        $serverConn = ServerConnection::where('website_id', $website->id)->firstOrFail();
        $storageConn = StorageConnection::where('workspace_id', $workspace->id)->firstOrFail();

        // 1. Create staging directory with a test file
        $stagingDir = ArchivePackagerService::createEphemeralStagingDir('test-enc-run');
        file_put_contents($stagingDir . '/database-dump.sql', 'CREATE TABLE users (id INT PRIMARY KEY);');

        // 2. Package with native AES-256 encryption
        $passphrase = 'TestPassphrase@2026';
        $package = ArchivePackagerService::packageAndEncrypt(
            stagingDir: $stagingDir,
            archiveBaseName: 'test_protected_archive',
            enableEncryption: true,
            passphrase: $passphrase
        );

        $this->assertTrue($package['is_encrypted']);
        $this->assertStringEndsWith('.zip', $package['file_name']);
        $this->assertFileExists($package['local_path']);

        // 3. Verify native password protection: reading without password fails
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($package['local_path']));
        $this->assertFalse($zip->getFromName('database-dump.sql')); // Fails without password!
        $zip->setPassword($passphrase);
        $this->assertStringContainsString('CREATE TABLE', (string) $zip->getFromName('database-dump.sql'));
        $zip->close();

        // 4. Verify verifyPassphrase helper
        $this->assertTrue(ArchivePackagerService::verifyPassphrase($package['local_path'], $passphrase));
        $this->assertFalse(ArchivePackagerService::verifyPassphrase($package['local_path'], 'WrongPassword'));

        // 5. Store in vault-mirror for download streaming
        $remotePath = 'test-vault/test_protected_archive.zip';
        Storage::disk('local')->put('vault-mirror/' . $remotePath, file_get_contents($package['local_path']));

        $policy = BackupPolicy::create([
            'workspace_id' => $workspace->id,
            'website_id' => $website->id,
            'server_connection_id' => $serverConn->id,
            'storage_connection_id' => $storageConn->id,
            'name' => 'Test Policy',
            'scope' => 'full_stack',
            'strategy' => 'full_only',
            'full_reset_every_n' => 1,
            'schedule_frequency' => 'daily',
            'interval_minutes' => 1440,
            'cron_expression' => '0 2 * * *',
            'retention_days' => 14,
            'download_mode' => 'one_time_token',
            'status' => 'active',
            'enable_encryption' => true,
        ]);
        $chain = \App\Models\BackupChain::create([
            'workspace_id' => $workspace->id,
            'website_id' => $website->id,
            'backup_policy_id' => $policy->id,
            'chain_type' => 'full',
            'status' => 'active',
        ]);

        $run = BackupRun::create([
            'workspace_id' => $workspace->id,
            'website_id' => $website->id,
            'backup_policy_id' => $policy->id,
            'backup_chain_id' => $chain->id,
            'trigger_type' => 'manual',
            'backup_type' => 'full',
            'scope' => 'full_stack',
            'status' => 'completed',
        ]);

        $artifact = BackupArtifact::create([
            'workspace_id' => $workspace->id,
            'backup_run_id' => $run->id,
            'storage_connection_id' => $storageConn->id,
            'artifact_type' => 'combined_archive',
            'remote_path' => $remotePath,
            'file_name' => $package['file_name'],
            'size_bytes' => $package['size_bytes'],
            'sha256_checksum' => $package['sha256_checksum'],
            'compression_algo' => 'zip',
            'is_encrypted' => true,
            'verification_status' => 'verified',
        ]);

        // 6. Test downloading unencrypted on the fly via one-time download token
        $issued = DownloadTokenService::issueDownloadLink(
            artifact: $artifact,
            decryptionPassphrase: $passphrase
        );

        $response = $this->get($issued['url']);
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/zip');

        // Capture streamed content to verify it is completely unencrypted
        ob_start();
        $response->sendContent();
        $streamedContent = ob_get_clean();

        $tempDownloadedZip = tempnam(sys_get_temp_dir(), 'test_dl_');
        file_put_contents($tempDownloadedZip, $streamedContent);

        $downloadedZip = new \ZipArchive();
        $this->assertTrue($downloadedZip->open($tempDownloadedZip));
        // Can read directly WITHOUT entering any password!
        $this->assertStringContainsString('CREATE TABLE', (string) $downloadedZip->getFromName('database-dump.sql'));
        $downloadedZip->close();

        @unlink($tempDownloadedZip);

        // 7. Test re-encrypting with a separate Client Password on-the-fly
        $clientPassword = 'ClientPass#9988';
        $clientIssued = DownloadTokenService::issueDownloadLink(
            artifact: $artifact,
            decryptionPassphrase: $passphrase,
            customReEncryptPassphrase: $clientPassword
        );

        $clientResponse = $this->get($clientIssued['url']);
        $clientResponse->assertStatus(200);

        ob_start();
        $clientResponse->sendContent();
        $clientStreamed = ob_get_clean();

        $tempClientZip = tempnam(sys_get_temp_dir(), 'test_client_dl_');
        file_put_contents($tempClientZip, $clientStreamed);

        $clientZip = new \ZipArchive();
        $this->assertTrue($clientZip->open($tempClientZip));

        // Master password does NOT open it
        $clientZip->setPassword($passphrase);
        $this->assertFalse($clientZip->getFromName('database-dump.sql'));

        // New client password DOES open it
        $clientZip->setPassword($clientPassword);
        $this->assertStringContainsString('CREATE TABLE', (string) $clientZip->getFromName('database-dump.sql'));
        $clientZip->close();

        @unlink($tempClientZip);
        // 8. Test 2nd click on the same one-time download link gracefully renders friendly error page
        $replayResponse = $this->get($clientIssued['url']);
        $replayResponse->assertStatus(410);
        $replayResponse->assertSee('Download Link Already Used or Expired');
        $replayResponse->assertSee('Return to Dashboard');

        @unlink($package['local_path']);
        ArchivePackagerService::wipeEphemeralStagingDir($stagingDir);
    }
}
