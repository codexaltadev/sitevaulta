<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'referral_code')) {
                $table->string('referral_code', 40)->nullable()->unique()->after('timezone');
            }
            if (! Schema::hasColumn('users', 'referred_by_code')) {
                $table->string('referred_by_code', 40)->nullable()->index()->after('referral_code');
            }
        });

        Schema::table('workspaces', function (Blueprint $table) {
            if (! Schema::hasColumn('workspaces', 'email_credits_balance')) {
                $table->integer('email_credits_balance')->default(50)->after('timezone');
            }
            if (! Schema::hasColumn('workspaces', 'email_provider_mode')) {
                $table->string('email_provider_mode', 30)->default('system_wallet')->after('email_credits_balance');
            }
            if (! Schema::hasColumn('workspaces', 'custom_smtp_config')) {
                $table->longText('custom_smtp_config')->nullable()->after('email_provider_mode');
            }
            if (! Schema::hasColumn('workspaces', 'referral_code')) {
                $table->string('referral_code', 40)->nullable()->unique()->after('custom_smtp_config');
            }
            if (! Schema::hasColumn('workspaces', 'referred_by_workspace_id')) {
                $table->foreignId('referred_by_workspace_id')->nullable()->after('referral_code')->constrained('workspaces')->nullOnDelete();
            }
            if (! Schema::hasColumn('workspaces', 'referral_rewarded_at')) {
                $table->timestamp('referral_rewarded_at')->nullable()->after('referred_by_workspace_id');
            }
        });

        Schema::table('backup_policies', function (Blueprint $table) {
            if (! Schema::hasColumn('backup_policies', 'email_provider_mode')) {
                $table->string('email_provider_mode', 30)->default('workspace_default')->after('download_mode');
            }
        });

        Schema::table('backup_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('backup_runs', 'email_provider_mode')) {
                $table->string('email_provider_mode', 30)->default('workspace_default')->after('trigger_tag');
            }
        });

        if (! Schema::hasTable('email_packages')) {
            Schema::create('email_packages', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('name', 120);
                $table->string('description', 255)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->unsignedInteger('credits_amount')->default(500);
                $table->unsignedInteger('bonus_credits')->default(0);
                $table->decimal('price_bdt', 10, 2)->default(0);
                $table->decimal('price_usd', 10, 2)->default(0);
                $table->boolean('is_featured')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('email_wallet_transactions')) {
            Schema::create('email_wallet_transactions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->string('type', 40)->index(); // welcome_bonus, package_purchase, referral_signup_bonus, referral_purchase_reward, email_sent_deduction, admin_adjustment
                $table->integer('credits_delta');
                $table->integer('balance_after');
                $table->string('description', 255);
                $table->string('reference_type', 80)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('referrals')) {
            Schema::create('referrals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('referrer_workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->foreignId('referred_workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
                $table->foreignId('referred_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('referral_code_used', 40)->index();
                $table->string('status', 30)->default('registered')->index(); // registered, converted
                $table->unsignedInteger('signup_bonus_credits')->default(0);
                $table->unsignedInteger('purchase_reward_credits')->default(0);
                $table->unsignedInteger('referee_bonus_credits')->default(0);
                $table->foreignId('converted_payment_id')->nullable()->constrained('payments')->nullOnDelete();
                $table->timestamp('converted_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('email_wallet_transactions');
        Schema::dropIfExists('email_packages');

        Schema::table('backup_runs', function (Blueprint $table) {
            if (Schema::hasColumn('backup_runs', 'email_provider_mode')) {
                $table->dropColumn('email_provider_mode');
            }
        });

        Schema::table('backup_policies', function (Blueprint $table) {
            if (Schema::hasColumn('backup_policies', 'email_provider_mode')) {
                $table->dropColumn('email_provider_mode');
            }
        });

        Schema::table('workspaces', function (Blueprint $table) {
            if (Schema::hasColumn('workspaces', 'referred_by_workspace_id')) {
                $table->dropConstrainedForeignId('referred_by_workspace_id');
            }
            $cols = array_filter(
                ['email_credits_balance', 'email_provider_mode', 'custom_smtp_config', 'referral_code', 'referral_rewarded_at'],
                fn (string $c) => Schema::hasColumn('workspaces', $c)
            );
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $cols = array_filter(
                ['referral_code', 'referred_by_code'],
                fn (string $c) => Schema::hasColumn('users', $c)
            );
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};

