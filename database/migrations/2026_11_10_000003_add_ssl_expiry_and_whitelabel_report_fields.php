<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'ssl_status')) {
                $table->string('ssl_status', 30)->default('unknown')->after('server_live_status');
            }
            if (! Schema::hasColumn('websites', 'ssl_issuer')) {
                $table->string('ssl_issuer', 191)->nullable()->after('ssl_status');
            }
            if (! Schema::hasColumn('websites', 'ssl_expires_at')) {
                $table->timestamp('ssl_expires_at')->nullable()->after('ssl_issuer');
            }
            if (! Schema::hasColumn('websites', 'ssl_days_remaining')) {
                $table->integer('ssl_days_remaining')->nullable()->after('ssl_expires_at');
            }
            if (! Schema::hasColumn('websites', 'notify_on_ssl_expiry')) {
                $table->boolean('notify_on_ssl_expiry')->default(true)->after('notify_on_malware_detected');
            }
            if (! Schema::hasColumn('websites', 'ssl_expiry_warning_days')) {
                $table->unsignedInteger('ssl_expiry_warning_days')->default(14)->after('notify_on_ssl_expiry');
            }
            if (! Schema::hasColumn('websites', 'last_ssl_expiry_notified_at')) {
                $table->timestamp('last_ssl_expiry_notified_at')->nullable()->after('ssl_expiry_warning_days');
            }
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            if (! Schema::hasColumn('workspaces', 'report_brand_name')) {
                $table->string('report_brand_name', 150)->nullable()->after('custom_smtp_config');
            }
            if (! Schema::hasColumn('workspaces', 'report_brand_logo_url')) {
                $table->string('report_brand_logo_url', 500)->nullable()->after('report_brand_name');
            }
            if (! Schema::hasColumn('workspaces', 'report_footer_note')) {
                $table->string('report_footer_note', 500)->nullable()->after('report_brand_logo_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $cols = [
                'ssl_status',
                'ssl_issuer',
                'ssl_expires_at',
                'ssl_days_remaining',
                'notify_on_ssl_expiry',
                'ssl_expiry_warning_days',
                'last_ssl_expiry_notified_at',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('websites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            $cols = ['report_brand_name', 'report_brand_logo_url', 'report_footer_note'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('workspaces', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

