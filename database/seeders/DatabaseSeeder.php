<?php

namespace Database\Seeders;

use App\Enums\BackupScope;
use App\Enums\BackupStrategy;
use App\Enums\DatabaseDriver;
use App\Enums\FileProtocol;
use App\Enums\ScheduleFrequency;
use App\Enums\StorageDriver;
use App\Enums\WorkspaceRole;
use App\Models\BackupTemplate;
use App\Models\EmailTemplate;
use App\Models\Plan;
use App\Models\ServerConnection;
use App\Models\StorageConnection;
use App\Models\User;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Billing\EmailWalletService;
use App\Services\Billing\EntitlementService;
use App\Services\Settings\SystemSettingsService;
use App\Services\Settings\WorkspaceSettingsService;
use App\Services\Verification\DomainVerificationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedDynamicSystemSettings();
        $this->seedEmailTemplates();
        $this->seedPlans();
        EmailWalletService::seedDefaultEmailPackages();
        $this->seedGlobalBackupTemplates();
        $this->seedRolesAndUsers();
    }

    protected function seedDynamicSystemSettings(): void
    {
        $settings = [
            // Group 1: General & Branding
            ['general', 'app_name', 'SiteVaulta', 'string', false, true, 'Platform Brand Name', 'Displayed across navigation, emails, and landing page'],
            ['general', 'tagline', 'Your Sites. Your Storage. Your Control.', 'string', false, true, 'Platform Tagline', 'Main value proposition tagline'],
            ['general', 'support_email', 'support@sitevaulta.com', 'string', false, true, 'Support Contact Email', 'Customer support email address'],
            ['general', 'support_phone', '+880 1700-000000', 'string', false, true, 'Support Contact Phone', 'Customer support phone number'],
            ['general', 'footer_text', 'Enterprise-grade server and database backup automation with zero storage lock-in. Stream encrypted backups directly to your own cloud.', 'text', false, true, 'Footer Brand Description', 'Displayed in landing page footer'],
            ['general', 'logo_light_url', '', 'string', false, true, 'Light Mode Logo URL', 'Optional custom logo URL for light mode'],
            ['general', 'logo_dark_url', '', 'string', false, true, 'Dark Mode Logo URL', 'Optional custom logo URL for dark mode'],
            ['general', 'favicon_url', '', 'string', false, true, 'Favicon URL', 'Optional custom favicon URL'],
            ['general', 'default_currency', 'BDT', 'string', false, true, 'Default Currency', 'BDT or USD'],
            ['general', 'default_timezone', 'Asia/Dhaka', 'string', false, true, 'Default Timezone', 'System default display timezone'],
            ['general', 'usd_to_bdt_rate', 120.0, 'float', false, true, 'USD to BDT Exchange Rate', 'Used when converting currency dynamically'],

            // Group 2: Trial & Subscription Rules
            ['trial_billing', 'default_trial_days', 7, 'integer', false, true, 'Free Trial Duration (Days)', 'Number of days a new workspace receives on the Free Trial'],
            ['trial_billing', 'grace_period_days', 3, 'integer', false, false, 'Renewal Grace Period (Days)', 'Days allowed after billing expiry before pausing backups'],
            ['trial_billing', 'expired_metadata_retention_days', 30, 'integer', false, false, 'Expired Account Metadata Retention (Days)', 'Days to preserve workspace metadata after subscription expires'],
            ['trial_billing', 'tax_percentage', 0.0, 'float', false, true, 'Tax / VAT Percentage (%)', 'Applied to subscription checkouts if greater than 0'],
            ['trial_billing', 'renewal_window_days', 7, 'integer', false, true, 'Same-Plan Renewal Window (Days)', 'Days before expiry when same-plan renewal unlocks'],
            ['email_wallet_referral', 'welcome_free_email_credits', 50, 'integer', false, true, 'Welcome Free Email Credits', 'Starter System Email credits granted to every new workspace'],
            ['email_wallet_referral', 'referral_signup_bonus_credits', 25, 'integer', false, true, 'Referral Signup Bonus Credits', 'Email credits awarded to referrer when a workspace registers'],
            ['email_wallet_referral', 'referral_purchase_referrer_credits', 500, 'integer', false, true, 'Referral Purchase Reward (Referrer Credits)', 'Email credits awarded to referrer when referred user purchases a package'],
            ['email_wallet_referral', 'referral_purchase_referee_credits', 200, 'integer', false, true, 'Referral Purchase Bonus (New User Credits)', 'Bonus email credits awarded to referred user on first package purchase'],

            // Group 3: Backup Engine & Forecast Defaults
            ['backup_engine', 'default_compression_ratio', 0.60, 'float', false, true, 'Default Compression Ratio', '0.60 = 40% compression savings for pre-backup forecast'],
            ['backup_engine', 'default_incremental_delta_rate', 0.05, 'float', false, true, 'Default Incremental Change Rate', '0.05 = 5% estimated daily file change rate'],
            ['backup_engine', 'default_full_reset_every_n', 7, 'integer', false, true, 'Default Full Reset Count', 'New Full Backup after N incremental runs'],
            ['backup_engine', 'default_retention_days', 14, 'integer', false, true, 'Default Retention Period (Days)', 'Default retention days for new policies'],
            ['backup_engine', 'job_timeout_seconds', 7200, 'integer', false, false, 'Max Backup Job Timeout (Seconds)', 'Maximum execution time per backup run'],
            ['backup_engine', 'max_retry_attempts', 3, 'integer', false, false, 'Max Job Retry Attempts', 'Number of retries on transient failure'],
            ['backup_engine', 'retry_backoff_seconds', 60, 'integer', false, false, 'Retry Backoff Time (Seconds)', 'Delay before retrying a failed backup job'],
            ['backup_engine', 'anomaly_drop_threshold_percent', 50, 'integer', false, false, 'Anomaly Size Drop Threshold (%)', 'Flag warning if archive shrinks by more than this %'],
            ['backup_engine', 'anomaly_spike_threshold_percent', 200, 'integer', false, false, 'Anomaly Size Spike Threshold (%)', 'Flag warning if archive grows by more than this %'],
            ['backup_engine', 'default_exclude_paths', ['.git', 'node_modules', 'vendor', 'storage/logs/*.log', '*.tmp', 'cache/*'], 'json', false, true, 'Global Default Exclude Paths', 'Default excluded folders/patterns'],
            ['backup_engine', 'enabled_file_protocols', ['ssh_sftp', 'ftp_ftps', 'cpanel_api', 'vaultix_agent'], 'json', false, true, 'Enabled Server File Protocols', 'Protocols available to users'],
            ['backup_engine', 'enabled_storage_drivers', ['google_drive', 's3', 'cloudflare_r2', 's3_compatible', 'sftp'], 'json', false, true, 'Enabled Storage Providers', 'Storage drivers available to users'],

            // Group 4: Download & Security Rules
            ['security', 'one_time_token_ttl_minutes', 15, 'integer', false, false, 'One-Time Download Token Expiry (Minutes)', 'Validity window for one-time download links'],
            ['security', 'presigned_url_ttl_minutes', 15, 'integer', false, false, 'Presigned URL Expiry (Minutes)', 'Validity window for S3/R2 signed download URLs'],
            ['security', 'max_download_resume_retries', 3, 'integer', false, false, 'Max Download Stream Retries', 'Allowed retries if a large download is interrupted'],
            ['security', 'block_private_ips_ssrf', true, 'boolean', false, false, 'Enforce SSRF Private IP Protection', 'Block localhost and private RFC1918 IP ranges'],
            ['security', 'enforce_2fa', false, 'boolean', false, false, 'Enforce 2FA Across Workspaces', 'Require two-factor authentication for workspace members'],
            ['security', 'ssrf_custom_blocked_ips', [], 'json', false, false, 'Custom SSRF Blocklist IP Ranges', 'Additional blocked IPs or hostnames'],

            // Group 5: Payment Gateways (SSLCOMMERZ, Stripe, PayPal)
            ['payment_sslcommerz', 'enabled', true, 'boolean', false, true, 'Enable SSLCOMMERZ (BDT)', 'Allow bKash, Nagad, and local card payments'],
            ['payment_sslcommerz', 'sandbox_mode', true, 'boolean', false, false, 'SSLCOMMERZ Sandbox Mode', 'Use sandbox environment for testing'],
            ['payment_sslcommerz', 'store_name', 'testcodexi9zk', 'string', false, false, 'SSLCOMMERZ Store Name', 'Merchant Store Name'],
            ['payment_sslcommerz', 'store_id', 'codex6ae2d3cc64516', 'string', true, false, 'SSLCOMMERZ Store ID', 'Merchant Store ID'],
            ['payment_sslcommerz', 'store_password', 'qjynKMkpu1gj', 'string', true, false, 'SSLCOMMERZ Store Password', 'Merchant Store Password'],

            ['payment_stripe', 'enabled', true, 'boolean', false, true, 'Enable Stripe (USD)', 'Allow international credit/debit cards'],
            ['payment_stripe', 'public_key', 'pk_test_placeholder', 'string', false, true, 'Stripe Publishable Key', 'pk_test_... or pk_live_...'],
            ['payment_stripe', 'secret_key', 'sk_test_placeholder', 'string', true, false, 'Stripe Secret Key', 'Encrypted secret key'],
            ['payment_stripe', 'webhook_secret', 'whsec_placeholder', 'string', true, false, 'Stripe Webhook Signing Secret', 'Encrypted webhook secret'],

            ['payment_paypal', 'enabled', true, 'boolean', false, true, 'Enable PayPal (USD)', 'Allow PayPal express & subscription checkout'],
            ['payment_paypal', 'sandbox_mode', true, 'boolean', false, false, 'PayPal Sandbox Mode', 'Use PayPal sandbox API'],
            ['payment_paypal', 'client_id', 'paypal_client_id_placeholder', 'string', false, true, 'PayPal Client ID', 'REST API Client ID'],
            ['payment_paypal', 'client_secret', 'paypal_secret_placeholder', 'string', true, false, 'PayPal Client Secret', 'Encrypted REST API Secret'],

            // Group 6: Landing Page CMS
            ['landing_cms', 'hero_title', 'Automated Website & Server Backups to Your Own Storage', 'string', false, true, 'Landing Hero Title', 'Main headline on homepage'],
            ['landing_cms', 'hero_subtitle', 'Verify your domain, connect SSH/SFTP, FTP, cPanel, or MySQL/PostgreSQL, and stream encrypted full & incremental backups directly to your Google Drive, Amazon S3, Cloudflare R2, or VPS.', 'text', false, true, 'Landing Hero Subtitle', 'Subheadline on homepage'],
            ['landing_cms', 'cta_primary_text', 'Start 7-Day Free Trial', 'string', false, true, 'Primary CTA Button Text', 'Hero call-to-action button label'],
        ];

        foreach ($settings as [$group, $key, $value, $type, $isEncrypted, $isPublic, $label, $helper]) {
            SystemSettingsService::set($group, $key, $value, $type, $isEncrypted, $isPublic, $label, $helper);
        }
    }

    protected function seedEmailTemplates(): void
    {
        $templates = [
            [
                'code' => 'welcome_trial',
                'name' => 'Welcome & 7-Day Free Trial Activated',
                'subject' => 'Welcome to {app_name} — Your {trial_days}-Day Free Trial is Active!',
                'body_html' => '<p>Hello <strong>{user_name}</strong>,</p><p>Your workspace <strong>{workspace_name}</strong> has been created with a {trial_days}-day free trial. Verify your first website and connect your storage to schedule automated backups.</p>',
                'available_placeholders' => ['app_name', 'user_name', 'workspace_name', 'trial_days'],
            ],
            [
                'code' => 'backup_succeeded',
                'name' => 'Backup Run Succeeded Alert',
                'subject' => '[SUCCESS] Backup Completed & Verified for {website_name} ({domain})',
                'body_html' => '<p>A backup run (<code>{run_uuid}</code>) for <strong>{website_name}</strong> ({domain}) under policy <strong>{policy_name}</strong> completed and verified successfully.</p><p><strong>Archive Size:</strong> {archive_size}</p>',
                'available_placeholders' => ['website_name', 'domain', 'policy_name', 'run_uuid', 'archive_size'],
            ],
            [
                'code' => 'backup_failed',
                'name' => 'Backup Run Failed Alert',
                'subject' => '[ALERT] Backup Failed for {website_name} ({domain})',
                'body_html' => '<p>A scheduled backup for <strong>{website_name}</strong> ({domain}) failed during stage <code>{status}</code>.</p><p><strong>Diagnostic Error:</strong> {error_message}</p>',
                'available_placeholders' => ['website_name', 'domain', 'policy_name', 'status', 'error_message'],
            ],
            [
                'code' => 'anomaly_detected',
                'name' => 'Smart Backup Size Anomaly Warning',
                'subject' => '[WARNING] Unusual Backup Archive Size for {website_name}',
                'body_html' => '<p>Backup run <code>{run_uuid}</code> for <strong>{website_name}</strong> completed, but our anomaly detector flagged an unusual size change:</p><p><strong>{anomaly_reason}</strong> (Current size: {archive_size}).</p>',
                'available_placeholders' => ['website_name', 'run_uuid', 'anomaly_reason', 'archive_size'],
            ],
        ];

        foreach ($templates as $tpl) {
            EmailTemplate::updateOrCreate(['code' => $tpl['code']], $tpl);
        }
    }

    protected function seedPlans(): void
    {
        $plans = [
            [
                'code' => 'free',
                'name' => 'Free Trial',
                'description' => '7-Day full-featured trial to test end-to-end backup & recovery.',
                'sort_order' => 1,
                'is_trial' => true,
                'trial_days' => 7,
                'price_bdt_monthly' => 0,
                'price_bdt_yearly' => 0,
                'price_usd_monthly' => 0,
                'price_usd_yearly' => 0,
                'yearly_discount_percent' => 0,
                'max_websites' => 1,
                'max_policies' => 1,
                'max_storage_connections' => 1,
                'max_team_members' => 1,
                'min_interval_minutes' => 1440,
                'max_retention_days' => 7,
                'max_monitors' => 10,
                'min_monitor_interval_seconds' => 300,
                'max_status_pages' => 1,
                'features' => [
                    'incremental' => true,
                    'encryption' => true,
                    'webhooks' => false,
                    'whitelabel_reports' => false,
                    'templates' => false,
                ],
                'is_featured' => false,
            ],
            [
                'code' => 'starter',
                'name' => 'Starter',
                'description' => 'Ideal for solo developers and small business owners.',
                'sort_order' => 2,
                'is_trial' => false,
                'trial_days' => 0,
                'price_bdt_monthly' => 590,
                'price_bdt_yearly' => 5664,
                'price_usd_monthly' => 5.00,
                'price_usd_yearly' => 48.00,
                'yearly_discount_percent' => 20,
                'max_websites' => 3,
                'max_policies' => 3,
                'max_storage_connections' => 2,
                'max_team_members' => 1,
                'min_interval_minutes' => 360,
                'max_retention_days' => 30,
                'max_monitors' => 25,
                'min_monitor_interval_seconds' => 120,
                'max_status_pages' => 1,
                'features' => [
                    'incremental' => true,
                    'encryption' => true,
                    'webhooks' => true,
                    'whitelabel_reports' => false,
                    'templates' => true,
                ],
                'is_featured' => false,
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'description' => 'Built for freelance developers managing multiple client websites.',
                'sort_order' => 3,
                'is_trial' => false,
                'trial_days' => 0,
                'price_bdt_monthly' => 1490,
                'price_bdt_yearly' => 14304,
                'price_usd_monthly' => 14.00,
                'price_usd_yearly' => 134.40,
                'yearly_discount_percent' => 20,
                'max_websites' => 10,
                'max_policies' => 20,
                'max_storage_connections' => 5,
                'max_team_members' => 3,
                'min_interval_minutes' => 60,
                'max_retention_days' => 90,
                'max_monitors' => 100,
                'min_monitor_interval_seconds' => 60,
                'max_status_pages' => 5,
                'features' => [
                    'incremental' => true,
                    'encryption' => true,
                    'webhooks' => true,
                    'whitelabel_reports' => false,
                    'templates' => true,
                ],
                'is_featured' => true,
            ],
            [
                'code' => 'agency',
                'name' => 'Agency',
                'description' => 'Complete multi-client backup automation with white-label PDF reports.',
                'sort_order' => 4,
                'is_trial' => false,
                'trial_days' => 0,
                'price_bdt_monthly' => 3490,
                'price_bdt_yearly' => 33504,
                'price_usd_monthly' => 32.00,
                'price_usd_yearly' => 307.20,
                'yearly_discount_percent' => 20,
                'max_websites' => 30,
                'max_policies' => 60,
                'max_storage_connections' => 15,
                'max_team_members' => 10,
                'min_interval_minutes' => 60,
                'max_retention_days' => 365,
                'max_monitors' => 300,
                'min_monitor_interval_seconds' => 60,
                'max_status_pages' => 999,
                'features' => [
                    'incremental' => true,
                    'encryption' => true,
                    'webhooks' => true,
                    'whitelabel_reports' => true,
                    'templates' => true,
                ],
                'is_featured' => false,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], $plan);
        }
    }

    protected function seedGlobalBackupTemplates(): void
    {
        $templates = [
            [
                'name' => 'Standard Daily Incremental (14-Day Retention)',
                'is_global_default' => true,
                'scope' => BackupScope::FullStack,
                'strategy' => BackupStrategy::IncrementalWithPeriodicFull,
                'full_reset_every_n' => 7,
                'schedule_frequency' => ScheduleFrequency::Daily,
                'interval_minutes' => 1440,
                'cron_expression' => '0 2 * * *',
                'retention_days' => 14,
                'exclude_paths' => ['.git', 'node_modules', 'vendor', 'storage/logs/*.log', '*.tmp'],
                'enable_encryption' => true,
                'notification_events' => ['on_failure', 'on_anomaly'],
            ],
            [
                'name' => 'E-Commerce High-Frequency Database (Hourly DB, 7-Day Retention)',
                'is_global_default' => true,
                'scope' => BackupScope::DatabaseOnly,
                'strategy' => BackupStrategy::FullOnly,
                'full_reset_every_n' => 1,
                'schedule_frequency' => ScheduleFrequency::Hourly,
                'interval_minutes' => 60,
                'cron_expression' => '0 * * * *',
                'retention_days' => 7,
                'exclude_paths' => [],
                'enable_encryption' => true,
                'notification_events' => ['on_failure', 'on_anomaly'],
            ],
        ];

        foreach ($templates as $tpl) {
            BackupTemplate::updateOrCreate(
                ['name' => $tpl['name'], 'is_global_default' => true],
                $tpl
            );
        }
    }

    protected function seedRolesAndUsers(): void
    {
        $roles = [
            'super_admin',
            'support_admin',
            'billing_manager',
            'system_auditor',
        ];

        foreach ($roles as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        // Seed Filament Shield permissions for all Admin resources
        $shieldResources = [
            'Workspace',
            'User',
            'Plan',
            'EmailPackage',
            'Coupon',
            'Payment',
            'BackupRun',
            'AuditLog',
            'EmailTemplate',
            'Role',
        ];
        $shieldPrefixes = ['ViewAny', 'View', 'Create', 'Update', 'Delete'];
        $allPermissions = [];
        foreach ($shieldResources as $res) {
            foreach ($shieldPrefixes as $prefix) {
                $perm = Permission::firstOrCreate([
                    'name' => "{$prefix}:{$res}",
                    'guard_name' => 'web',
                ]);
                $allPermissions[] = $perm->name;
            }
        }

        Role::findByName('super_admin', 'web')->syncPermissions($allPermissions);
        Role::findByName('support_admin', 'web')->syncPermissions([
            'ViewAny:Workspace', 'View:Workspace',
            'ViewAny:BackupRun', 'View:BackupRun',
            'ViewAny:AuditLog', 'View:AuditLog',
        ]);
        Role::findByName('billing_manager', 'web')->syncPermissions([
            'ViewAny:Plan', 'View:Plan', 'Create:Plan', 'Update:Plan',
            'ViewAny:Coupon', 'View:Coupon', 'Create:Coupon', 'Update:Coupon',
            'ViewAny:Payment', 'View:Payment',
        ]);
        Role::findByName('system_auditor', 'web')->syncPermissions([
            'ViewAny:AuditLog', 'View:AuditLog',
            'ViewAny:BackupRun', 'View:BackupRun',
            'ViewAny:Payment', 'View:Payment',
        ]);

        // 1. Super Admin User (/admin & /app)
        $admin = User::updateOrCreate(
            ['email' => 'admin@sitevaulta.com'],
            [
                'name' => 'SiteVaulta Super Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'can_access_admin_panel' => true,
            ]
        );
        $admin->assignRole('super_admin');

        // Create a default workspace for the admin too so they can test /app seamlessly
        $adminWorkspace = Workspace::firstOrCreate(
            ['slug' => 'sitevaulta-hq'],
            [
                'name' => 'SiteVaulta HQ Workspace',
                'owner_id' => $admin->id,
                'billing_currency' => 'BDT',
                'timezone' => 'Asia/Dhaka',
            ]
        );
        $adminWorkspace->members()->syncWithoutDetaching([
            $admin->id => ['role' => WorkspaceRole::Owner->value],
        ]);
        WorkspaceSettingsService::initializeDefaultsForWorkspace($adminWorkspace);
        EmailWalletService::initializeWelcomeCredits($adminWorkspace);
        if (! $adminWorkspace->currentSubscription) {
            EntitlementService::provisionFreeTrial($adminWorkspace);
        }

        // 2. Demo Customer User (/app)
        $customer = User::updateOrCreate(
            ['email' => 'demo@sitevaulta.com'],
            [
                'name' => 'Demo Agency Owner',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'can_access_admin_panel' => false,
            ]
        );

        $demoWorkspace = Workspace::firstOrCreate(
            ['slug' => 'demo-agency'],
            [
                'name' => 'Demo Web Agency',
                'owner_id' => $customer->id,
                'billing_currency' => 'BDT',
                'timezone' => 'Asia/Dhaka',
            ]
        );
        $demoWorkspace->members()->syncWithoutDetaching([
            $customer->id => ['role' => WorkspaceRole::Owner->value],
        ]);
        WorkspaceSettingsService::initializeDefaultsForWorkspace($demoWorkspace);
        EmailWalletService::initializeWelcomeCredits($demoWorkspace);
        if (! $demoWorkspace->currentSubscription) {
            EntitlementService::provisionFreeTrial($demoWorkspace);
        }

        // Seed a verified Demo Website, ServerConnection, and StorageConnection for demo-agency
        $demoWebsite = Website::firstOrCreate(
            ['workspace_id' => $demoWorkspace->id, 'domain' => 'acme-commerce.example.com'],
            [
                'name' => 'Acme Commerce Production',
                'url' => 'https://acme-commerce.example.com',
                'environment' => 'production',
                'known_files_size_bytes' => 450 * 1024 * 1024,
                'known_db_size_bytes' => 85 * 1024 * 1024,
                'status' => 'active',
            ]
        );

        DomainVerificationService::ensureChallengeExists($demoWebsite)->update(['verification_method' => 'dns_txt', 'verified_at' => now()]);

        ServerConnection::firstOrCreate(
            ['workspace_id' => $demoWorkspace->id, 'website_id' => $demoWebsite->id],
            [
                'name' => 'Acme Production VPS (SSH/SFTP + MySQL)',
                'file_protocol' => FileProtocol::SshSftp,
                'file_credentials' => [
                    'host' => '198.51.100.25',
                    'port' => 22,
                    'username' => 'deploy',
                    'root_path' => '/var/www/acme-commerce',
                    'simulation_mode' => true,
                ],
                'db_driver' => DatabaseDriver::MySql,
                'db_use_ssh_tunnel' => true,
                'db_credentials' => [
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'acme_prod_db',
                    'username' => 'acme_user',
                ],
                'capabilities' => ['files' => true, 'db' => true, 'inc_files' => true],
                'health_status' => 'healthy',
                'last_tested_at' => now(),
            ]
        );

        StorageConnection::firstOrCreate(
            ['workspace_id' => $demoWorkspace->id, 'name' => 'Agency Cloudflare R2 Vault'],
            [
                'driver' => StorageDriver::CloudflareR2,
                'credentials' => [
                    'bucket' => 'demo-agency-vault',
                    'region' => 'auto',
                    'endpoint' => 'https://demo.r2.cloudflarestorage.com',
                    'simulation_mode' => true,
                ],
                'root_prefix' => 'sitevaulta-backups',
                'supports_presigned_url' => true,
                'quota_total_bytes' => 100 * 1024 * 1024 * 1024,
                'quota_used_bytes' => 2 * 1024 * 1024 * 1024,
                'health_status' => 'healthy',
                'last_verified_at' => now(),
            ]
        );
    }
}
