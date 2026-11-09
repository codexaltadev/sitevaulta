<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Workspaces (Customer Tenants in /app Panel)
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('billing_currency', 10)->default('BDT');
            $table->string('timezone', 64)->default('Asia/Dhaka');
            $table->json('whitelabel_config')->nullable();
            $table->boolean('is_suspended')->default(false);
            $table->timestamps();
        });

        // 2. Workspace User Pivot (Team Members & Roles inside a Workspace)
        Schema::create('workspace_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 30)->default('operator');
            $table->json('permissions')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        // 3. System Settings (100% Dynamic Admin-Controlled Configuration)
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 60)->index();
            $table->string('key', 100);
            $table->longText('value')->nullable();
            $table->string('type', 30)->default('string'); // string, integer, float, boolean, json, text
            $table->boolean('is_encrypted')->default(false);
            $table->boolean('is_public')->default(false);
            $table->string('label', 150);
            $table->string('helper_text', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group', 'key']);
        });

        // 4. Workspace Settings (100% Dynamic Customer-Controlled Preferences)
        Schema::create('workspace_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('group', 60);
            $table->string('key', 100);
            $table->longText('value')->nullable();
            $table->string('type', 30)->default('string');
            $table->timestamps();

            $table->unique(['workspace_id', 'group', 'key']);
        });

        // 5. Email & Notification Templates (Dynamic Admin-Editable Templates)
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 120);
            $table->string('subject', 255);
            $table->longText('body_html');
            $table->json('available_placeholders')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 6. Plans (Dynamic Subscription Packages)
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_trial')->default(false);
            $table->unsignedSmallInteger('trial_days')->default(7);
            $table->decimal('price_bdt_monthly', 10, 2)->default(0);
            $table->decimal('price_bdt_yearly', 10, 2)->default(0);
            $table->decimal('price_usd_monthly', 10, 2)->default(0);
            $table->decimal('price_usd_yearly', 10, 2)->default(0);
            $table->decimal('yearly_discount_percent', 5, 2)->default(0);
            $table->unsignedInteger('max_websites')->default(1);
            $table->unsignedInteger('max_policies')->default(1);
            $table->unsignedInteger('max_storage_connections')->default(1);
            $table->unsignedInteger('max_team_members')->default(1);
            $table->unsignedInteger('min_interval_minutes')->default(1440);
            $table->unsignedInteger('max_retention_days')->default(7);
            $table->json('features');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 7. Subscriptions
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('billing_cycle', 30)->default('trial');
            $table->string('status', 30)->default('trialing')->index();
            $table->string('gateway', 30)->default('none');
            $table->string('gateway_subscription_id', 191)->nullable()->index();
            $table->timestamp('trial_starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable()->index();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable()->index();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->json('custom_quota_overrides')->nullable();
            $table->timestamps();
        });

        // 8. Coupons
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('discount_type', 30)->default('percentage'); // percentage, fixed_bdt, fixed_usd
            $table->decimal('discount_value', 10, 2);
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redeemed_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 9. Payments
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('invoice_number', 60)->unique();
            $table->string('gateway', 30);
            $table->string('gateway_transaction_id', 191)->nullable()->unique();
            $table->string('idempotency_key', 191)->unique();
            $table->string('currency', 10)->default('BDT');
            $table->decimal('amount', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->json('gateway_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // 10. Websites
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('url', 255);
            $table->string('domain', 191)->index();
            $table->string('environment', 30)->default('production');
            $table->string('verification_status', 30)->default('unverified')->index();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_method', 30)->nullable();
            $table->string('webhook_secret_hash', 255)->nullable();
            $table->unsignedBigInteger('known_files_size_bytes')->nullable();
            $table->unsignedBigInteger('known_db_size_bytes')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamps();
        });

        // 11. Website Verifications
        Schema::create('website_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('token', 100);
            $table->string('dns_host', 191);
            $table->string('file_path', 191);
            $table->string('meta_tag_name', 100)->default('sitevaulta-verification');
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        // 12. Server Connections (Stack-Blind File & Database Credentials)
        Schema::create('server_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('file_protocol', 30)->default('none');
            $table->longText('file_credentials')->nullable(); // encrypted:array
            $table->string('db_driver', 30)->default('none');
            $table->boolean('db_use_ssh_tunnel')->default(false);
            $table->longText('db_credentials')->nullable(); // encrypted:array
            $table->json('capabilities')->nullable();
            $table->string('health_status', 30)->default('untested');
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // 13. Storage Connections (BYOS Customer Storage)
        Schema::create('storage_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('driver', 40);
            $table->longText('credentials'); // encrypted:array
            $table->string('root_prefix', 191)->default('sitevaulta-backups');
            $table->boolean('supports_presigned_url')->default(false);
            $table->unsignedBigInteger('quota_total_bytes')->nullable();
            $table->unsignedBigInteger('quota_used_bytes')->default(0);
            $table->string('health_status', 30)->default('untested');
            $table->timestamp('last_verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // 14. Backup Templates
        Schema::create('backup_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->boolean('is_global_default')->default(false);
            $table->string('name', 120);
            $table->string('scope', 30)->default('full_stack');
            $table->string('strategy', 40)->default('incremental_with_periodic_full');
            $table->unsignedTinyInteger('full_reset_every_n')->default(7);
            $table->string('schedule_frequency', 30)->default('daily');
            $table->unsignedInteger('interval_minutes')->default(1440);
            $table->string('cron_expression', 100)->default('0 2 * * *');
            $table->unsignedSmallInteger('retention_days')->default(14);
            $table->json('exclude_paths')->nullable();
            $table->boolean('enable_encryption')->default(true);
            $table->json('notification_events')->nullable();
            $table->timestamps();
        });

        // 15. Backup Policies
        Schema::create('backup_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('server_connection_id')->constrained('server_connections')->cascadeOnDelete();
            $table->foreignId('storage_connection_id')->constrained('storage_connections')->restrictOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('backup_templates')->nullOnDelete();
            $table->string('name', 120);
            $table->string('scope', 30)->default('full_stack');
            $table->string('strategy', 40)->default('incremental_with_periodic_full');
            $table->unsignedTinyInteger('full_reset_every_n')->default(7);
            $table->string('schedule_frequency', 30)->default('daily');
            $table->unsignedInteger('interval_minutes')->default(1440);
            $table->string('cron_expression', 100)->default('0 2 * * *');
            $table->unsignedSmallInteger('retention_days')->default(14);
            $table->json('exclude_paths')->nullable();
            $table->boolean('enable_encryption')->default(false);
            $table->text('encryption_passphrase')->nullable(); // encrypted
            $table->string('download_mode', 30)->default('one_time_token');
            $table->string('notification_email', 191)->nullable();
            $table->boolean('notify_on_success')->default(true);
            $table->boolean('notify_on_failure')->default(true);
            $table->json('forecast_snapshot')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamps();
        });

        // 16. Backup Chains (Full + Incremental Dependency Graph)
        Schema::create('backup_chains', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('backup_policy_id')->constrained('backup_policies')->cascadeOnDelete();
            $table->unsignedBigInteger('base_full_run_id')->nullable()->index();
            $table->unsignedSmallInteger('incremental_count')->default(0);
            $table->unsignedBigInteger('total_chain_size_bytes')->default(0);
            $table->string('status', 30)->default('active')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        // 17. Backup Runs
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('backup_policy_id')->constrained('backup_policies')->cascadeOnDelete();
            $table->foreignId('backup_chain_id')->nullable()->constrained('backup_chains')->nullOnDelete();
            $table->foreignId('parent_run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->string('trigger_type', 30)->default('scheduled');
            $table->string('trigger_tag', 100)->nullable();
            $table->string('notification_email', 191)->nullable();
            $table->boolean('notify_on_success')->default(true);
            $table->boolean('notify_on_failure')->default(true);
            $table->string('backup_type', 30)->default('full');
            $table->string('scope', 30)->default('full_stack');
            $table->string('status', 30)->default('queued')->index();
            $table->unsignedInteger('source_files_count')->default(0);
            $table->unsignedInteger('changed_files_count')->default(0);
            $table->unsignedBigInteger('raw_source_size_bytes')->default(0);
            $table->unsignedBigInteger('archive_size_bytes')->default(0);
            $table->boolean('is_anomaly')->default(false);
            $table->string('anomaly_reason', 255)->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('retention_expires_at')->nullable()->index();
            $table->boolean('is_pruned')->default(false)->index();
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('notification_sent_at')->nullable();
            $table->timestamps();
        });

        // 18. Backup Artifacts
        Schema::create('backup_artifacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('backup_run_id')->constrained('backup_runs')->cascadeOnDelete();
            $table->foreignId('storage_connection_id')->constrained('storage_connections')->restrictOnDelete();
            $table->string('artifact_type', 40)->default('combined_archive');
            $table->string('remote_path', 512);
            $table->string('file_name', 255);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256_checksum', 64);
            $table->string('compression_algo', 20)->default('gzip');
            $table->boolean('is_encrypted')->default(false);
            $table->string('encryption_iv', 128)->nullable();
            $table->longText('file_index_manifest')->nullable();
            $table->string('verification_status', 30)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        // 19. Download Tokens
        Schema::create('download_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('backup_artifact_id')->constrained('backup_artifacts')->cascadeOnDelete();
            $table->string('mode', 30)->default('one_time_token');
            $table->string('status', 30)->default('issued')->index();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        // 20. Notification Channels
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 30);
            $table->longText('config'); // encrypted:array
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 21. Client Reports (Agency White-Label Reports)
        Schema::create('client_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('recipient_email', 191);
            $table->string('frequency', 30)->default('monthly');
            $table->timestamp('last_sent_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 22. Audit Logs
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 100)->index();
            $table->string('auditable_type', 120)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('client_reports');
        Schema::dropIfExists('notification_channels');
        Schema::dropIfExists('download_tokens');
        Schema::dropIfExists('backup_artifacts');
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('backup_chains');
        Schema::dropIfExists('backup_policies');
        Schema::dropIfExists('backup_templates');
        Schema::dropIfExists('storage_connections');
        Schema::dropIfExists('server_connections');
        Schema::dropIfExists('website_verifications');
        Schema::dropIfExists('websites');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('workspace_settings');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
    }
};

