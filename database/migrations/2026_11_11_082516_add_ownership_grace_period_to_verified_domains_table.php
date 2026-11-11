<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily ownership re-check with a 7-day grace period: when the token is conclusively gone, the countdown
 * starts (token_missing_since), reminders are sent (last_reminder_day) and after 7 days the verification
 * lapses (ownership_lapsed_at) — which pauses backups / port checks until the token is back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verified_domains', function (Blueprint $table): void {
            $table->timestamp('token_missing_since')->nullable()->after('last_error');
            $table->unsignedTinyInteger('last_reminder_day')->nullable()->after('token_missing_since');
            $table->timestamp('ownership_lapsed_at')->nullable()->after('last_reminder_day');
            $table->dropColumn('reverify_failures');
        });
    }

    public function down(): void
    {
        Schema::table('verified_domains', function (Blueprint $table): void {
            $table->unsignedTinyInteger('reverify_failures')->default(0)->after('last_error');
            $table->dropColumn(['token_missing_since', 'last_reminder_day', 'ownership_lapsed_at']);
        });
    }
};
