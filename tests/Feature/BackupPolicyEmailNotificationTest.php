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
}
