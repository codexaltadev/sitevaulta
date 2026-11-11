<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slow-site alert: when an HTTP/keyword monitor answers slower than slow_threshold_ms for
 * slow_alert_after_checks checks in a row, the owner is alerted (the site is still up, so no incident).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->unsignedInteger('slow_threshold_ms')->nullable()->after('alert_repeat_minutes');
            $table->unsignedTinyInteger('slow_alert_after_checks')->default(3)->after('slow_threshold_ms');
            $table->unsignedSmallInteger('consecutive_slow_checks')->default(0)->after('slow_alert_after_checks');
            $table->timestamp('slow_alerted_at')->nullable()->after('consecutive_slow_checks');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['slow_threshold_ms', 'slow_alert_after_checks', 'consecutive_slow_checks', 'slow_alerted_at']);
        });
    }
};
