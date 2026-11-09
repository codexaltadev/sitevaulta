<?php

namespace Tests\Feature;

use App\Enums\BackupRunStatus;
use App\Enums\BillingCycle;
use App\Enums\PaymentGateway;
use App\Jobs\Backup\ExecuteBackupRunJob;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\EmailPackage;
use App\Models\Plan;
use App\Models\ServerConnection;
use App\Models\StorageConnection;
use App\Models\User;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Billing\EmailWalletService;
use App\Services\Billing\MultiGatewayBillingService;
use App\Services\Notifications\WorkspaceAlertDispatcher;
use App\Services\Security\WebsiteSecurityScannerService;
use App\Services\Verification\WebsiteUptimeMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupPolicyEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_policy_run_sends_email_on_success_and_failure_and_deducts_wallet_credits_or_uses_free_custom_smtp(): void
    {
        $workspace = Workspace::where('slug', 'demo-agency')->firstOrFail();
        $website = Website::where('workspace_id', $workspace->id)->firstOrFail();
        $serverConn = ServerConnection::where('website_id', $website->id)->firstOrFail();
        $storageConn = StorageConnection::where('workspace_id', $workspace->id)->firstOrFail();

        $initialCredits = (int) $workspace->email_credits_balance;
        $this->assertGreaterThanOrEqual(50, $initialCredits);

        // 1. System Email Wallet Mode: sends email and deducts 1 credit from Digital Email Wallet
        $policy = BackupPolicy::create([
            'workspace_id' => $workspace->id,
            'website_id' => $website->id,
            'server_connection_id' => $serverConn->id,
            'storage_connection_id' => $storageConn->id,
            'name' => 'Email Alert Policy',
            'scope' => 'full_stack',
            'strategy' => 'full_only',
            'full_reset_every_n' => 1,
            'schedule_frequency' => 'daily',
            'interval_minutes' => 1440,
            'cron_expression' => '0 2 * * *',
            'retention_days' => 14,
            'download_mode' => 'one_time_token',
            'email_provider_mode' => 'system_wallet',
            'notification_email' => 'ops-alert@example.com',
            'notify_on_success' => true,
            'notify_on_failure' => true,
            'status' => 'active',
        ]);

        $job = new ExecuteBackupRunJob($policy->id, 'manual');
        $run = $job->handle();

        $this->assertNotNull($run);
        $this->assertEquals(BackupRunStatus::Completed, $run->status);
        $this->assertEquals('system_wallet', $run->email_provider_mode);
        $this->assertNotNull($run->notification_sent_at);

        $workspace->refresh();
        $this->assertEquals($initialCredits - 1, $workspace->email_credits_balance);

        // 2. When Digital Wallet has 0 credits, System Email is blocked until topped up
        $workspace->update(['email_credits_balance' => 0]);

        $failedRunZeroBalance = BackupRun::create([
            'workspace_id' => $workspace->id,
            'website_id' => $website->id,
            'backup_policy_id' => $policy->id,
            'trigger_type' => 'manual',
            'email_provider_mode' => 'system_wallet',
            'notification_email' => 'ops-alert@example.com',
            'notify_on_success' => true,
            'notify_on_failure' => true,
            'backup_type' => 'full',
            'scope' => 'full_stack',
            'status' => BackupRunStatus::Failed,
            'error_message' => 'Simulated error',
        ]);

        $sentWithZeroCredits = WorkspaceAlertDispatcher::sendPolicyRunEmail($policy->fresh(), $failedRunZeroBalance, isSuccess: false);
        $this->assertFalse($sentWithZeroCredits);

        // 3. Custom SMTP Mode (Free): sends email with 0 wallet credits deducted!
        $workspace->update([
            'email_provider_mode' => 'custom_smtp',
            'custom_smtp_config' => [
                'host' => 'smtp.custom-agency.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'alerts@custom-agency.com',
                'password' => 'secret123',
                'from_address' => 'alerts@custom-agency.com',
                'from_name' => 'Custom Agency Alerts',
            ],
        ]);
        $policy->update(['email_provider_mode' => 'custom_smtp']);

        $customSmtpRun = BackupRun::create([
            'workspace_id' => $workspace->id,
            'website_id' => $website->id,
            'backup_policy_id' => $policy->id,
            'trigger_type' => 'manual',
            'email_provider_mode' => 'custom_smtp',
            'notification_email' => 'ops-alert@example.com',
            'notify_on_success' => true,
            'notify_on_failure' => true,
            'backup_type' => 'full',
            'scope' => 'full_stack',
            'status' => BackupRunStatus::Completed,
        ]);

        $sentViaCustomSmtp = WorkspaceAlertDispatcher::sendPolicyRunEmail($policy->fresh(), $customSmtpRun, isSuccess: true);
        $this->assertTrue($sentViaCustomSmtp);
        $this->assertEquals(0, $workspace->fresh()->email_credits_balance);
        $this->assertEquals('custom_smtp', $customSmtpRun->fresh()->email_provider_mode);
    }

    public function test_email_package_purchase_and_referral_reward_digital_wallet_system(): void
    {
        $referrerWorkspace = Workspace::where('slug', 'demo-agency')->firstOrFail();
        $refCode = EmailWalletService::ensureReferralCode($referrerWorkspace);
        $referrerInitialBalance = (int) $referrerWorkspace->email_credits_balance;

        // New user creates a referred workspace
        $newUser = User::factory()->create(['email' => 'referred-founder@example.com']);
        $referredWorkspace = Workspace::create([
            'name' => 'Referred Startup Workspace',
            'slug' => 'referred-startup',
            'owner_id' => $newUser->id,
            'billing_currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
        ]);
        EmailWalletService::initializeWelcomeCredits($referredWorkspace);

        // Apply referral code
        $referral = EmailWalletService::applyReferralCodeToWorkspace($referredWorkspace, $refCode, $newUser);
        $this->assertNotNull($referral);
        $this->assertEquals('registered', $referral->status);

        // Referrer gets +25 signup bonus credits immediately
        $referrerWorkspace->refresh();
        $this->assertEquals($referrerInitialBalance + 25, $referrerWorkspace->email_credits_balance);

        // Referred workspace purchases a paid Subscription Plan (or Email Package)
        $proPlan = Plan::where('code', 'pro')->firstOrFail();
        MultiGatewayBillingService::fulfillSuccessfulPayment(
            workspace: $referredWorkspace,
            plan: $proPlan,
            cycle: BillingCycle::Monthly,
            gateway: PaymentGateway::SslCommerz,
            gatewayTransactionId: 'TXN-REF-TEST-001',
            idempotencyKey: 'ref_test_001',
            currency: 'BDT',
            amount: 1490.00
        );

        // Verify Referrer received +500 Purchase Reward Credits and Referee received +200 Bonus Credits!
        $referrerWorkspace->refresh();
        $referredWorkspace->refresh();
        $referral->refresh();

        $this->assertEquals('converted', $referral->status);
        $this->assertEquals($referrerInitialBalance + 25 + 500, $referrerWorkspace->email_credits_balance);
        $this->assertEquals(50 + 200, $referredWorkspace->email_credits_balance);

        // Also test purchasing an Email Credit Package directly
        $emailPkg = EmailPackage::where('code', 'email_growth_2500')->firstOrFail();
        MultiGatewayBillingService::fulfillEmailPackagePayment(
            workspace: $referredWorkspace,
            emailPackage: $emailPkg,
            gateway: PaymentGateway::SslCommerz,
            gatewayTransactionId: 'TXN-EML-PKG-002',
            idempotencyKey: 'eml_pkg_002',
            currency: 'BDT',
            amount: 690.00
        );

        $referredWorkspace->refresh();
        // 250 + 2500 base + 250 bonus = 3000 credits!
        $this->assertEquals(250 + $emailPkg->totalCredits(), $referredWorkspace->email_credits_balance);
    }

    public function test_smart_email_fallback_and_website_uptime_monitoring_owner_alerts(): void
    {
        $workspace = Workspace::where('slug', 'demo-agency')->firstOrFail();
        $website = Website::where('workspace_id', $workspace->id)->firstOrFail();

        // Case A: User selects 'custom_smtp' on website, but Custom SMTP is NOT configured on workspace.
        // Wallet has 50 credits -> Should automatically fallback to 'system_wallet' and deduct 1 credit!
        $workspace->update([
            'email_credits_balance' => 50,
            'custom_smtp_config' => null,
        ]);

        $website->update([
            'uptime_monitoring_enabled' => true,
            'uptime_status' => 'online',
            'uptime_alert_email' => 'owner-alert@example.com',
            'uptime_email_provider_mode' => 'custom_smtp',
            'notify_on_downtime' => true,
            'notify_on_recovery' => true,
        ]);

        // 1. Simulate Website/Server going DOWN
        $downResult = WebsiteUptimeMonitorService::checkWebsiteLiveStatus($website->fresh(), forceSimulateOnline: false);
        $this->assertFalse($downResult['is_online']);
        $this->assertEquals('down', $downResult['uptime_status']);
        $this->assertTrue($downResult['alert_sent']);

        $workspace->refresh();
        $website->refresh();
        $this->assertEquals('down', $website->uptime_status);
        $this->assertNotNull($website->last_downtime_notified_at);
        // Because custom_smtp was unavailable, it auto-fell back to system_wallet and deducted 1 credit (50 -> 49)
        $this->assertEquals(49, $workspace->email_credits_balance);

        // Case B: User selects 'system_wallet' on website, but wallet balance becomes 0!
        // However, Custom SMTP IS configured -> Should automatically fallback to 'custom_smtp' (0 credits needed)!
        $workspace->update([
            'email_credits_balance' => 0,
            'custom_smtp_config' => [
                'host' => 'smtp.owner-server.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'noreply@owner-server.com',
                'password' => 'smtp-pass-123',
                'from_address' => 'noreply@owner-server.com',
                'from_name' => 'Owner Server SMTP',
            ],
        ]);
        $website->update([
            'uptime_email_provider_mode' => 'system_wallet',
        ]);

        // 2. Simulate Website/Server RECOVERING back to ONLINE
        $recoveryResult = WebsiteUptimeMonitorService::checkWebsiteLiveStatus($website->fresh(), forceSimulateOnline: true);
        $this->assertTrue($recoveryResult['is_online']);
        $this->assertEquals('online', $recoveryResult['uptime_status']);
        $this->assertTrue($recoveryResult['alert_sent']);

        $website->refresh();
        $this->assertEquals('online', $website->uptime_status);
        $this->assertEquals(0, $workspace->fresh()->email_credits_balance);

        // 3. Real un-simulated check on fake domain acme-commerce.example.com must detect it as DOWN!
        $realCheckResult = WebsiteUptimeMonitorService::checkWebsiteLiveStatus($website->fresh());
        $this->assertFalse($realCheckResult['is_online']);
        $this->assertEquals('down', $realCheckResult['uptime_status']);

        $intel = WebsiteUptimeMonitorService::inspectSiteAndServerIntelligence($website->fresh());
        $this->assertTrue($intel['is_fake_domain']);
        $this->assertNull($intel['primary_ip']);
        $this->assertArrayHasKey('protection_score', $intel);
        $this->assertArrayHasKey('ssl', $intel);
    }

    public function test_read_only_malware_and_webshell_scanner_reports_exact_locations_without_deleting_files(): void
    {
        $workspace = Workspace::where('slug', 'demo-agency')->firstOrFail();
        $website = Website::where('workspace_id', $workspace->id)->firstOrFail();

        $workspace->update(['email_credits_balance' => 20]);
        $website->update([
            'uptime_alert_email' => 'security-owner@example.com',
            'notify_on_malware_detected' => true,
        ]);

        // Create a temporary staged directory simulating files read from the server
        $tempDir = sys_get_temp_dir().'/sitevaulta_sec_scan_'.uniqid();
        mkdir($tempDir.'/wp-content/uploads/2026', 0755, true);

        $cleanFile = $tempDir.'/index.php';
        $webshellFile = $tempDir.'/wp-content/uploads/2026/wso.php';
        $obfuscatedFile = $tempDir.'/wp-content/themes/header.php';
        mkdir(dirname($obfuscatedFile), 0755, true);

        file_put_contents($cleanFile, "<?php\n// Normal entry point\necho 'Hello World';\n");
        file_put_contents($webshellFile, "<?php\n// Suspicious shell in uploads\n\$cmd = \$_POST['cmd'];\nshell_exec(\$_POST['cmd']);\n");
        file_put_contents($obfuscatedFile, "<?php\n// Theme header\neval(base64_decode('ZWNobyBoYWNrZWQ7'));\n");

        $scanResult = WebsiteSecurityScannerService::scanWebsiteAndServer(
            website: $website,
            localStagedFilesDir: $tempDir,
            sendAlertIfThreatsFound: true
        );

        // 1. Verify threats are accurately detected and reported with exact paths
        $this->assertEquals('infected', $scanResult['status']);
        $this->assertGreaterThanOrEqual(3, $scanResult['critical_count']);
        $this->assertTrue($scanResult['email_notified']);

        $locations = array_column($scanResult['findings'], 'location');
        $this->assertContains('/var/www/acme-commerce/wp-content/uploads/2026/wso.php', $locations);
        $this->assertContains('/var/www/acme-commerce/wp-content/themes/header.php', $locations);

        // 2. 100% READ-ONLY GUARANTEE: Verify NO files were deleted or modified by the scanner!
        $this->assertFileExists($cleanFile);
        $this->assertFileExists($webshellFile);
        $this->assertFileExists($obfuscatedFile);

        $website->refresh();
        $this->assertEquals('infected', $website->security_scan_status);
        $this->assertGreaterThanOrEqual(3, $website->security_threats_count);
        $this->assertNotNull($website->last_security_scan_at);

        // 3. Verify in-app Database Notifications were saved to the notifications table for the Workspace Owner!
        $owner = $workspace->owner;
        $this->assertNotNull($owner);
        $this->assertGreaterThan(0, $owner->notifications()->count());

        // Clean up temp test directory
        @unlink($cleanFile);
        @unlink($webshellFile);
        @unlink($obfuscatedFile);
        @rmdir($tempDir.'/wp-content/uploads/2026');
        @rmdir($tempDir.'/wp-content/uploads');
        @rmdir($tempDir.'/wp-content/themes');
        @rmdir($tempDir.'/wp-content');
        @rmdir($tempDir);
    }

    public function test_ssl_expiry_auto_reminder_and_whitelabel_client_pdf_report(): void
    {
        $workspace = Workspace::where('slug', 'demo-agency')->firstOrFail();
        $website = Website::where('workspace_id', $workspace->id)->firstOrFail();
        $owner = $workspace->owner;

        $workspace->update([
            'email_credits_balance' => 30,
            'report_brand_name' => 'Apex Digital Agency Ltd.',
            'report_footer_note' => 'Prepared by Apex Digital Security & Cloud Backup Team',
        ]);

        $website->update([
            'notify_on_ssl_expiry' => true,
            'ssl_expiry_warning_days' => 14,
            'uptime_alert_email' => 'ssl-owner@example.com',
        ]);

        // 1. Test SSL Certificate Expiry Auto-Reminder (7 days remaining <= 14 day threshold)
        $sslResult = WebsiteUptimeMonitorService::checkWebsiteLiveStatus(
            website: $website->fresh(),
            forceSimulateOnline: true,
            forceSimulateSslDaysRemaining: 7
        );

        $this->assertEquals('expiring_soon', $sslResult['ssl_status']);
        $this->assertEquals(7, $sslResult['ssl_days_remaining']);
        $this->assertTrue($sslResult['ssl_expiry_alert_sent']);

        $website->refresh();
        $this->assertEquals('expiring_soon', $website->ssl_status);
        $this->assertEquals(7, $website->ssl_days_remaining);
        $this->assertNotNull($website->last_ssl_expiry_notified_at);

        // 2. Test White-Label Client Executive Maintenance Report (Web Preview & PDF Download)
        $this->actingAs($owner);

        $previewResp = $this->get(route('websites.client-report.view', [
            'workspaceSlug' => $workspace->slug,
            'websiteUuid' => $website->uuid,
            'period' => 30,
        ]));
        $previewResp->assertStatus(200);
        $previewResp->assertSee('Apex Digital Agency Ltd.');
        $previewResp->assertSee('Prepared by Apex Digital Security & Cloud Backup Team');
        $previewResp->assertSee($website->domain);
        $previewResp->assertSee('7 Days Left');

        $pdfResp = $this->get(route('websites.client-report.pdf', [
            'workspaceSlug' => $workspace->slug,
            'websiteUuid' => $website->uuid,
        ]));
        $pdfResp->assertStatus(200);
    }
}
