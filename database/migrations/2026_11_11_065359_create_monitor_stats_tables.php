<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-hour and per-day check totals. Uptime %, daily bars and the 7-day response chart read these small
 * tables instead of scanning raw monitor_checks, which can then be pruned after a few weeks.
 * Existing checks are rolled up by `php artisan sitevaulta:rebuild-monitor-stats`.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['monitor_hourly_stats' => 'period_start', 'monitor_daily_stats' => 'period_start'] as $tableName => $periodColumn) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName, $periodColumn): void {
                $table->id();
                $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
                $tableName === 'monitor_daily_stats'
                    ? $table->date($periodColumn)
                    : $table->timestamp($periodColumn);
                $table->unsignedInteger('total_checks')->default(0);
                $table->unsignedInteger('down_checks')->default(0);
                $table->unsignedBigInteger('response_time_sum')->default(0);
                $table->unsignedInteger('response_time_count')->default(0);
                $table->unsignedInteger('response_time_min')->nullable();
                $table->unsignedInteger('response_time_max')->nullable();

                $table->unique(['monitor_id', $periodColumn]);
                $table->index($periodColumn);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_daily_stats');
        Schema::dropIfExists('monitor_hourly_stats');
    }
};
