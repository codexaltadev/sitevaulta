<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * next_check_at lets the scheduler pick due monitors with one indexed query instead of loading every
 * monitor each minute; first_checked_at keeps "monitoring since" correct after old raw checks are pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->timestamp('next_check_at')->nullable()->after('last_checked_at')->index();
            $table->timestamp('first_checked_at')->nullable()->after('next_check_at');
        });

        $firstChecks = DB::table('monitor_checks')
            ->selectRaw('monitor_id, min(checked_at) as first_checked_at')
            ->groupBy('monitor_id')
            ->pluck('first_checked_at', 'monitor_id');

        foreach ($firstChecks as $monitorId => $firstCheckedAt) {
            DB::table('monitors')->where('id', $monitorId)->update(['first_checked_at' => $firstCheckedAt]);
        }

        // next_check_at stays null for existing monitors, which means "due now"; the first run sets it.
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropIndex(['next_check_at']);
            $table->dropColumn(['next_check_at', 'first_checked_at']);
        });
    }
};
