<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            if (! Schema::hasColumn('websites', 'uptime_monitoring_enabled')) {
                $table->boolean('uptime_monitoring_enabled')->default(true)->after('status');
            }
            if (! Schema::hasColumn('websites', 'uptime_status')) {
                $table->string('uptime_status', 30)->default('unknown')->index()->after('uptime_monitoring_enabled');
            }
            if (! Schema::hasColumn('websites', 'last_http_status_code')) {
                $table->unsignedSmallInteger('last_http_status_code')->nullable()->after('uptime_status');
            }
            if (! Schema::hasColumn('websites', 'last_response_time_ms')) {
                $table->unsignedInteger('last_response_time_ms')->nullable()->after('last_http_status_code');
            }
            if (! Schema::hasColumn('websites', 'server_live_status')) {
                $table->string('server_live_status', 30)->default('unknown')->after('last_response_time_ms');
            }
            if (! Schema::hasColumn('websites', 'last_uptime_checked_at')) {
                $table->timestamp('last_uptime_checked_at')->nullable()->after('server_live_status');
            }
            if (! Schema::hasColumn('websites', 'last_down_reason')) {
                $table->string('last_down_reason', 255)->nullable()->after('last_uptime_checked_at');
            }
            if (! Schema::hasColumn('websites', 'uptime_alert_email')) {
                $table->string('uptime_alert_email', 191)->nullable()->after('last_down_reason');
            }
            if (! Schema::hasColumn('websites', 'uptime_email_provider_mode')) {
                $table->string('uptime_email_provider_mode', 30)->default('workspace_default')->after('uptime_alert_email');
            }
            if (! Schema::hasColumn('websites', 'notify_on_downtime')) {
                $table->boolean('notify_on_downtime')->default(true)->after('uptime_email_provider_mode');
            }
            if (! Schema::hasColumn('websites', 'notify_on_recovery')) {
                $table->boolean('notify_on_recovery')->default(true)->after('notify_on_downtime');
            }
            if (! Schema::hasColumn('websites', 'last_downtime_notified_at')) {
                $table->timestamp('last_downtime_notified_at')->nullable()->after('notify_on_recovery');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $cols = array_filter([
                'uptime_monitoring_enabled',
                'uptime_status',
                'last_http_status_code',
                'last_response_time_ms',
                'server_live_status',
                'last_uptime_checked_at',
                'last_down_reason',
                'uptime_alert_email',
                'uptime_email_provider_mode',
                'notify_on_downtime',
                'notify_on_recovery',
                'last_downtime_notified_at',
            ], fn (string $c) => Schema::hasColumn('websites', $c));

            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};

