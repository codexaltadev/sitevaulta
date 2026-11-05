<?php

namespace Tests\Feature;

use App\Enums\BackupRunStatus;
use App\Enums\BackupScope;
use App\Enums\BackupStrategy;
use App\Enums\BillingCycle;
use App\Enums\DownloadMode;
use App\Enums\ScheduleFrequency;
use App\Enums\SubscriptionStatus;
use App\Jobs\Backup\ExecuteBackupRunJob;
use App\Jobs\Backup\PruneExpiredBackupsJob;
use App\Models\BackupPolicy;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ServerConnection;
use App\Models\StorageConnection;
use App\Models\User;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Forecast\StorageForecastService;
use App\Services\Restore\DownloadTokenService;
use App\Services\Restore\RestoreGuideGenerator;
use App\Services\Security\SsrfValidator;
use App\Services\Settings\SystemSettingsService;
use App\Services\Settings\WorkspaceSettingsService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SiteVaultaEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_dual_panel_rbac_and_tenant_isolation(): void
    {
        $admin = User::where('email', 'admin@sitevaulta.com')->firstOrFail();
        $customer = User::where('email', 'demo@sitevaulta.com')->firstOrFail();
        $workspace = Workspace::where('slug', 'demo-agency')->firstOrFail();

        $adminPanel = Filament::getPanel('admin');
        $appPanel = Filament::getPanel('app');

        $this->assertTrue($admin->canAccessPanel($adminPanel));
        $this->assertFalse($customer->canAccessPanel($adminPanel));
        $this->assertTrue($customer->canAccessPanel($appPanel));
        $this->assertTrue($customer->canAccessTenant($workspace));

        // Verify all Customer Panel (/app/demo-agency/*) pages render 200 OK
        $appRoutes = [
            '/app/demo-agency',
            '/app/demo-agency/websites',
            '/app/demo-agency/websites/create',
            '/app/demo-agency/server-connections',
            '/app/demo-agency/server-connections/create',
            '/app/demo-agency/storage-connections',
            '/app/demo-agency/storage-connections/create',
            '/app/demo-agency/backup-templates',
            '/app/demo-agency/backup-templates/create',
            '/app/demo-agency/backup-policies',
            '/app/demo-agency/backup-policies/create',
            '/app/demo-agency/backup-runs',
            '/app/demo-agency/notification-channels',
            '/app/demo-agency/notification-channels/create',
            '/app/demo-agency/client-reports',
            '/app/demo-agency/client-reports/create',
            '/app/demo-agency/billing',
            '/app/demo-agency/team-members',
            '/app/demo-agency/profile',
        ];

        foreach ($appRoutes as $uri) {
            $this->actingAs($customer)->get($uri)->assertStatus(200);
        }
    }

    public function test_all_admin_panel_pages_render_cleanly(): void
    {
        $admin = User::where('email', 'admin@sitevaulta.com')->firstOrFail();

        $adminRoutes = [
            '/admin',
            '/admin/settings',
            '/admin/plans',
            '/admin/plans/create',
            '/admin/workspaces',
            '/admin/workspaces/create',
            '/admin/users',
            '/admin/users/create',
            '/admin/coupons',
            '/admin/coupons/create',
            '/admin/payments',
            '/admin/backup-run-admins',
            '/admin/audit-logs',
            '/admin/email-templates',
        ];

        foreach ($adminRoutes as $uri) {
            $this->actingAs($admin)->get($uri)->assertStatus(200);
        }
    }

    public function test_100_percent_dynamic_system_and_workspace_settings(): void
    {
        SystemSettingsService::set('general', 'app_name', 'SiteVaulta Enterprise Cloud');
        $this->assertSame('SiteVaulta Enterprise Cloud', SystemSettingsService::get('general', 'app_name'));

        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('SiteVaulta Enterprise Cloud');

        $workspace = Workspace::where('slug', 'demo-agency')->firstOrFail();
        WorkspaceSettingsService::set($workspace, 'backup_defaults', 'default_retention_days', 45, 'integer');
        $this->assertSame(45, (int) WorkspaceSettingsService::get($workspace, 'backup_defaults', 'default_retention_days'));
    }

    public function test_ssrf_validator_blocks_loopback_and_private_ranges(): void
    {
        foreach (['127.0.0.1', '10.0.0.15', '169.254.169.254', 'localhost'] as $blockedHost) {
            try {
                SsrfValidator::assertSafeHost($blockedHost);
                $this->fail("Expected SsrfValidator to block {$blockedHost}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('SSRF Protection', $e->getMessage());
            }
        }

        $this->assertSame('8.8.8.8', SsrfValidator::assertSafeHost('8.8.8.8'));
    }

    public function test_pre_backup_storage_and_transfer_forecaster(): void
    {
        $workspace = Workspace::where('slug', 'demo-agency')->firstOrFail();
        $website = Website::where('workspace_id', $workspace->id)->firstOrFail();
        $storageConn = StorageConnection::where('workspace_id', $workspace->id)->firstOrFail();

        $forecast = StorageForecastService::calculate(
            website: $website,
            scope: BackupScope::FullStack,
            strategy: BackupStrategy::IncrementalWithPeriodicFull,
            intervalMinutes: 1440,
            retentionDays: 14,
            fullResetEveryN: 7,
            storage: $storageConn,
        );

        $this->assertGreaterThan(0, $forecast['first_full_bytes']);
        $this->assertGreaterThan(0, $forecast['incremental_bytes']);
        $this->assertLessThan($forecast['first_full_bytes'], $forecast['incremental_bytes']);
        $this->assertSame(14, $forecast['total_backups_in_window']);
        $this->assertSame('healthy', $forecast['quota_status']);
    }

    public function test_hybrid_ephemeral_backup_full_and_incremental_chains_and_tmp_cleanup(): void
    {
        $workspace = Workspace::where('slug', 'demo-agency')->firstOrFail();
        $website = Website::where('workspace_id', $workspace->id)->firstOrFail();
        $serverConn = ServerConnection::where('website_id', $website->id)->firstOrFail();
        $storageConn = StorageConnection::where('workspace_id', $workspace->id)->firstOrFail();

        $policy = BackupPolicy::create([
            'workspace_id' => $workspace->id,
            'website_id' => $website->id,
            'server_connection_id' => $serverConn->id,
            'storage_connection_id' => $storageConn->id,
            'name' => 'Automated QA Chain Policy',
            'scope' => BackupScope::FullStack,
            'strategy' => BackupStrategy::IncrementalWithPeriodicFull,
            'full_reset_every_n' => 3,
            'schedule_frequency' => ScheduleFrequency::Daily,
            'interval_minutes' => 1440,
            'cron_expression' => '0 2 * * *',
            'retention_days' => 14,
            'exclude_paths' => ['.git', 'node_modules'],
            'enable_encryption' => true,
            'encryption_passphrase' => 'UltraSecretPassphrase#2026',
            'download_mode' => DownloadMode::OneTimeToken,
            'status' => 'active',
        ]);

        // Run #1 -> Must be a FULL backup starting Chain #1
        ExecuteBackupRunJob::dispatchSync($policy->id, 'manual');
        $firstRun = $policy->backupRuns()->orderBy('id')->first();

        $this->assertNotNull($firstRun);
        $this->assertSame(BackupRunStatus::Completed, $firstRun->status);
        $this->assertSame('full', $firstRun->backup_type);
        $this->assertSame(1, $firstRun->artifacts()->count());
        $this->assertNotEmpty($firstRun->artifacts()->first()->sha256_checksum);

        // Run #2 -> Must be an INCREMENTAL backup attached to the same chain
        ExecuteBackupRunJob::dispatchSync($policy->id, 'scheduled');
        $secondRun = $policy->backupRuns()->orderByDesc('id')->first();

        $this->assertSame(BackupRunStatus::Completed, $secondRun->status);
        $this->assertSame('incremental', $secondRun->backup_type);
        $this->assertSame($firstRun->backup_chain_id, $secondRun->backup_chain_id);

        // Verify ephemeral /tmp/sitevaulta/run-* directories were wiped
        $leftoverDirs = glob(sys_get_temp_dir() . '/sitevaulta/run-*') ?: [];
        $this->assertCount(0, $leftoverDirs);
    }

    public function test_one_time_atomic_download_token_and_restore_guide_generation(): void
    {
        $workspace = Workspace::where('slug', 'demo-agency')->firstOrFail();
        $website = Website::where('workspace_id', $workspace->id)->firstOrFail();
        $serverConn = ServerConnection::where('website_id', $website->id)->firstOrFail();
        $storageConn = StorageConnection::where('workspace_id', $workspace->id)->firstOrFail();

        $policy = BackupPolicy::create([
            'workspace_id' => $workspace->id,
            'website_id' => $website->id,
            'server_connection_id' => $serverConn->id,
            'storage_connection_id' => $storageConn->id,
            'name' => 'Restore QA Policy',
            'scope' => BackupScope::FullStack,
            'strategy' => BackupStrategy::FullOnly,
            'full_reset_every_n' => 1,
            'schedule_frequency' => ScheduleFrequency::Daily,
            'interval_minutes' => 1440,
            'cron_expression' => '0 2 * * *',
            'retention_days' => 14,
            'enable_encryption' => false,
            'download_mode' => DownloadMode::OneTimeToken,
            'status' => 'active',
        ]);

        ExecuteBackupRunJob::dispatchSync($policy->id, 'manual');
        $run = $policy->backupRuns()->firstOrFail();
        $artifact = $run->artifacts()->firstOrFail();

        $issued = DownloadTokenService::issueDownloadLink($artifact, DownloadMode::OneTimeToken);

        // 1st request succeeds (200 OK) with SHA-256 verification header
        $firstResponse = $this->get($issued['url']);
        $firstResponse->assertStatus(200);
        $firstResponse->assertHeader('X-SiteVaulta-Checksum-SHA256', $artifact->sha256_checksum);
        // Trigger the streamed response callback so markConsumed() executes
        $firstResponse->streamedContent();

        // 2nd replay request with the same one-time token is atomically blocked (410 Gone)
        $secondResponse = $this->get($issued['url']);
        $secondResponse->assertStatus(410);

        // Dynamic Restore Guide contains exact domain and SHA-256 checksum
        $guide = RestoreGuideGenerator::generateForRun($run);
        $this->assertSame($website->domain, $guide['website_domain']);
        $this->assertStringContainsString($artifact->sha256_checksum, $guide['commands']['linux_verify_checksum']);

        // Test encryption packaging and sitevaulta:decrypt-archive command
        $tmpDir = sys_get_temp_dir() . '/test_enc_' . uniqid();
        mkdir($tmpDir, 0777, true);
        file_put_contents($tmpDir . '/secret.txt', 'Secret content for encryption test');
        $encPack = \App\Services\Backup\ArchivePackagerService::packageAndEncrypt($tmpDir, 'test_enc_archive', true, 'SecretPass123!');
        $this->assertTrue($encPack['is_encrypted']);
        $this->assertFileExists($encPack['local_path']);

        $decryptedZip = sys_get_temp_dir() . '/test_enc_archive_decrypted.zip';
        $this->artisan('sitevaulta:decrypt-archive', [
            'file' => $encPack['local_path'],
            '--passphrase' => 'SecretPass123!',
            '--output' => $decryptedZip,
        ])->assertSuccessful();

        $this->assertFileExists($decryptedZip);
        @unlink($encPack['local_path']);
        @unlink($decryptedZip);
    }

    public function test_chain_safe_retention_pruning_protects_active_base_full_backup(): void
    {
        $workspace = Workspace::where('slug', 'demo-agency')->firstOrFail();
        $website = Website::where('workspace_id', $workspace->id)->firstOrFail();
        $serverConn = ServerConnection::where('website_id', $website->id)->firstOrFail();
        $storageConn = StorageConnection::where('workspace_id', $workspace->id)->firstOrFail();

        $policy = BackupPolicy::create([
            'workspace_id' => $workspace->id,
            'website_id' => $website->id,
            'server_connection_id' => $serverConn->id,
            'storage_connection_id' => $storageConn->id,
            'name' => 'Chain Pruning Policy',
            'scope' => BackupScope::FullStack,
            'strategy' => BackupStrategy::IncrementalWithPeriodicFull,
            'full_reset_every_n' => 1,
            'schedule_frequency' => ScheduleFrequency::Daily,
            'interval_minutes' => 1440,
            'cron_expression' => '0 2 * * *',
            'retention_days' => 7,
            'enable_encryption' => false,
            'download_mode' => DownloadMode::OneTimeToken,
            'status' => 'active',
        ]);

        // Run #1 (Full in Chain 1) and Run #2 (Incremental in Chain 1)
        ExecuteBackupRunJob::dispatchSync($policy->id, 'scheduled');
        ExecuteBackupRunJob::dispatchSync($policy->id, 'scheduled');
        // Run #3 (New Full in Chain 2 since full_reset_every_n = 1)
        ExecuteBackupRunJob::dispatchSync($policy->id, 'scheduled');

        $runs = $policy->backupRuns()->orderBy('id')->get();
        $fullRunChain1 = $runs[0];
        $incRunChain1 = $runs[1];

        // Expire ONLY the base full run of Chain 1, while its dependent incremental run is still unexpired
        $fullRunChain1->update(['retention_expires_at' => now()->subDays(2)]);
        $incRunChain1->update(['retention_expires_at' => now()->addDays(5)]);

        PruneExpiredBackupsJob::dispatchSync();

        // Chain-Safe rule: Base full run MUST NOT be pruned while dependent incremental is active
        $this->assertFalse($fullRunChain1->fresh()->is_pruned);

        // Now expire the incremental run too so all of Chain 1 is expired (and Chain 2 has a newer full backup)
        $incRunChain1->update(['retention_expires_at' => now()->subDay()]);
        PruneExpiredBackupsJob::dispatchSync();

        $this->assertTrue($fullRunChain1->fresh()->is_pruned);
        $this->assertTrue($incRunChain1->fresh()->is_pruned);
    }

    public function test_seven_day_trial_expiration_and_idempotent_multi_gateway_webhooks(): void
    {
        $workspace = Workspace::where('slug', 'demo-agency')->firstOrFail();
        $website = Website::where('workspace_id', $workspace->id)->firstOrFail();
        $serverConn = ServerConnection::where('website_id', $website->id)->firstOrFail();
        $storageConn = StorageConnection::where('workspace_id', $workspace->id)->firstOrFail();

        $policy = BackupPolicy::create([
            'workspace_id' => $workspace->id,
            'website_id' => $website->id,
            'server_connection_id' => $serverConn->id,
            'storage_connection_id' => $storageConn->id,
            'name' => 'Trial Lifecycle Policy',
            'scope' => BackupScope::FullStack,
            'strategy' => BackupStrategy::FullOnly,
            'full_reset_every_n' => 1,
            'schedule_frequency' => ScheduleFrequency::Daily,
            'interval_minutes' => 1440,
            'cron_expression' => '0 2 * * *',
            'retention_days' => 7,
            'enable_encryption' => false,
            'download_mode' => DownloadMode::OneTimeToken,
            'status' => 'active',
        ]);

        // Simulate 7-day trial expiration
        $sub = $workspace->currentSubscription;
        $sub->update([
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->subHour(),
        ]);

        $this->artisan('sitevaulta:dispatch-scheduled-backups --sync')->assertExitCode(0);

        $this->assertSame(SubscriptionStatus::Expired, $sub->fresh()->status);
        $this->assertSame('inactive_entitlement', $policy->fresh()->status);

        // Now upgrade via SSLCOMMERZ (BDT) IPN Webhook
        $proPlan = Plan::where('code', 'pro')->firstOrFail();
        $ipnPayload = [
            'status' => 'VALID',
            'tran_id' => 'SSLC-TX-998877',
            'val_id' => 'VAL-998877',
            'amount' => '1490.00',
            'currency' => 'BDT',
            'value_a' => (string) $workspace->id,
            'value_b' => $proPlan->code,
            'value_c' => BillingCycle::Monthly->value,
        ];

        $this->postJson('/webhooks/sslcommerz/ipn', $ipnPayload)->assertStatus(200);
        // Replay exact same webhook to verify idempotency (no duplicate payment row)
        $this->postJson('/webhooks/sslcommerz/ipn', $ipnPayload)->assertStatus(200);

        $this->assertSame(1, Payment::where('gateway_transaction_id', 'SSLC-TX-998877')->count());
        $this->assertSame(SubscriptionStatus::Active, $sub->fresh()->status);
        $this->assertSame($proPlan->id, $sub->fresh()->plan_id);
        $this->assertSame('active', $policy->fresh()->status);

        // Verify Hosted Sandbox Checkout Portal & Completion Redirect Flow
        $sandboxPayment = Payment::create([
            'workspace_id' => $workspace->id,
            'subscription_id' => $sub->id,
            'invoice_number' => 'INV-SANDBOX-001',
            'gateway' => \App\Enums\PaymentGateway::SslCommerz,
            'gateway_transaction_id' => 'SSLC-SANDBOX-001',
            'idempotency_key' => 'checkout_session_SSLC-SANDBOX-001',
            'currency' => 'BDT',
            'amount' => 3490.00,
            'status' => \App\Enums\PaymentStatus::Pending,
            'gateway_payload' => [
                'plan_id' => Plan::where('code', 'agency')->firstOrFail()->id,
                'plan_code' => 'agency',
                'billing_cycle' => BillingCycle::Monthly->value,
            ],
        ]);

        $this->get(route('billing.checkout.show', ['paymentUuid' => $sandboxPayment->invoice_number]))
            ->assertStatus(200)
            ->assertSee('Sandbox Mode');

        $this->post(route('billing.checkout.complete', ['paymentUuid' => $sandboxPayment->invoice_number]), [
            'payment_method' => 'bkash_sandbox',
        ])->assertRedirect("/app/{$workspace->slug}/billing?payment_status=success&invoice=INV-SANDBOX-001");

        $this->assertSame(\App\Enums\PaymentStatus::Succeeded, $sandboxPayment->fresh()->status);

        $customer = User::where('email', 'demo@sitevaulta.com')->firstOrFail();
        $this->actingAs($customer)
            ->get(route('billing.invoice.view', ['paymentUuid' => $sandboxPayment->invoice_number]))
            ->assertStatus(200)
            ->assertSee('TAX INVOICE')
            ->assertSee('PAID IN FULL');

        $this->actingAs($customer)
            ->get(route('billing.invoice.pdf', ['paymentUuid' => $sandboxPayment->invoice_number]))
            ->assertStatus(200);
    }

    public function test_storage_provider_credentials_and_google_oauth_callback(): void
    {
        // 1. Google Drive missing vs valid credentials check
        $missingGoogle = \App\Services\Storage\StorageAdapterManager::testCredentials('google_drive', [
            'client_id' => '',
            'client_secret' => '',
            'refresh_token' => '',
        ]);
        $this->assertFalse($missingGoogle['ok']);

        $validGoogle = \App\Services\Storage\StorageAdapterManager::testCredentials('google_drive', [
            'client_id' => '123456-test.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-testsecret123456',
            'refresh_token' => '1//04sv_test_generated_refresh_token_1234567890',
            'folder_id' => 'sitevaulta-backups',
        ]);
        $this->assertTrue($validGoogle['ok']);

        // 2. AWS S3 missing vs valid credentials check
        $missingS3 = \App\Services\Storage\StorageAdapterManager::testCredentials('s3', [
            'access_key_id' => 'AKIA123',
            'secret_access_key' => '',
            'bucket' => 'my-bucket',
            'region' => '',
        ]);
        $this->assertFalse($missingS3['ok']);

        $validS3 = \App\Services\Storage\StorageAdapterManager::testCredentials('s3', [
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'bucket' => 'my-s3-backup-bucket',
            'region' => 'us-east-1',
        ]);
        $this->assertTrue($validS3['ok']);

        // 3. Cloudflare R2 missing vs valid credentials check
        $missingR2 = \App\Services\Storage\StorageAdapterManager::testCredentials('cloudflare_r2', [
            'access_key_id' => 'r2key',
            'secret_access_key' => 'r2secret',
            'endpoint' => '',
            'bucket' => 'r2-bucket',
        ]);
        $this->assertFalse($missingR2['ok']);

        $validR2 = \App\Services\Storage\StorageAdapterManager::testCredentials('cloudflare_r2', [
            'access_key_id' => 'r2_access_key_123',
            'secret_access_key' => 'r2_secret_key_456',
            'endpoint' => 'https://8f9a2b3c4d5e.r2.cloudflarestorage.com',
            'bucket' => 'r2-backup-bucket',
        ]);
        $this->assertTrue($validR2['ok']);

        // 4. VPS / SFTP missing vs valid credentials check
        $missingVps = \App\Services\Storage\StorageAdapterManager::testCredentials('sftp', [
            'host' => '198.51.100.40',
            'port' => 22,
            'username' => '',
            'password' => '',
        ]);
        $this->assertFalse($missingVps['ok']);

        $validVps = \App\Services\Storage\StorageAdapterManager::testCredentials('sftp', [
            'host' => '198.51.100.40',
            'port' => 22,
            'username' => 'backupvault',
            'password' => 'SecretVpsPass123!',
            'root_directory' => '/var/backups/sitevaulta',
        ]);
        $this->assertTrue($validVps['ok']);

        // 5. Verify Google OAuth Callback routes (/auth/google/callback & /vaultix/auth/google/callback)
        $this->get('/auth/google/callback')
            ->assertStatus(200)
            ->assertSee('Google Drive OAuth Callback');

        $this->get('/vaultix/auth/google/callback')
            ->assertStatus(200)
            ->assertSee('Google Drive OAuth Callback');

        // 6. Verify Admin configurable Yearly Package Discount (%) on Plan
        $proPlan = Plan::where('code', 'pro')->firstOrFail();
        $proPlan->update([
            'price_bdt_monthly' => 1000.00,
            'yearly_discount_percent' => 25.00, // 25% OFF on Yearly (12,000 -> 9,000 BDT)
        ]);

        $yearlyPricing = \App\Services\Billing\MultiGatewayBillingService::calculateCheckoutPricing(
            $proPlan->fresh(),
            BillingCycle::Yearly,
            'BDT'
        );

        $this->assertEquals(12000.00, $yearlyPricing['regular_amount']);
        $this->assertEquals(25.00, $yearlyPricing['yearly_discount_percent']);
        $this->assertEquals(3000.00, $yearlyPricing['yearly_discount_amount']);
        $this->assertEquals(9000.00, $yearlyPricing['base_amount']);
        $this->assertEquals(9000.00, $yearlyPricing['total_amount']);
    }
}
