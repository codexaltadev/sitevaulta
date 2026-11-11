<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How long uptime data is kept, set per plan from the Admin panel: the detailed check log (every single
 * check) and the uptime history (hourly/daily totals behind uptime %, bars and status pages).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedSmallInteger('monitor_check_log_days')->default(30)->after('max_status_pages');
            $table->unsignedSmallInteger('monitor_history_days')->default(365)->after('monitor_check_log_days');
        });

        // Sensible tiers for the seeded plans; admins can change them any time.
        foreach (['free' => [7, 90], 'starter' => [30, 365], 'pro' => [90, 730], 'agency' => [90, 730]] as $code => [$checkLog, $history]) {
            DB::table('plans')->where('code', $code)->update(['monitor_check_log_days' => $checkLog, 'monitor_history_days' => $history]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['monitor_check_log_days', 'monitor_history_days']);
        });
    }
};
