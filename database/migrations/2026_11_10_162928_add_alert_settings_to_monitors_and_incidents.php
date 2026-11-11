<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->unsignedInteger('alert_delay_minutes')->default(0)->after('expiry_threshold_days');
            $table->unsignedInteger('alert_repeat_minutes')->default(0)->after('alert_delay_minutes');
            $table->json('alert_channel_ids')->nullable()->after('alert_repeat_minutes');
            $table->boolean('notify_workspace_members')->default(true)->after('alert_channel_ids');
        });

        Schema::table('monitor_incidents', function (Blueprint $table): void {
            $table->timestamp('alerted_at')->nullable()->after('duration_seconds');
            $table->timestamp('last_reminder_at')->nullable()->after('alerted_at');
            $table->unsignedInteger('reminder_count')->default(0)->after('last_reminder_at');
        });

        // Incidents that existed before this change already alerted when they opened.
        DB::table('monitor_incidents')->whereNull('alerted_at')->update(['alerted_at' => DB::raw('started_at')]);
    }

    public function down(): void
    {
        Schema::table('monitor_incidents', function (Blueprint $table): void {
            $table->dropColumn(['alerted_at', 'last_reminder_at', 'reminder_count']);
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['alert_delay_minutes', 'alert_repeat_minutes', 'alert_channel_ids', 'notify_workspace_members']);
        });
    }
};
