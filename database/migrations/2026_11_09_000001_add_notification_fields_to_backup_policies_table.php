<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_policies', function (Blueprint $table) {
            if (! Schema::hasColumn('backup_policies', 'notification_email')) {
                $table->string('notification_email', 191)->nullable()->after('download_mode');
            }
            if (! Schema::hasColumn('backup_policies', 'notify_on_success')) {
                $table->boolean('notify_on_success')->default(true)->after('notification_email');
            }
            if (! Schema::hasColumn('backup_policies', 'notify_on_failure')) {
                $table->boolean('notify_on_failure')->default(true)->after('notify_on_success');
            }
        });

        Schema::table('backup_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('backup_runs', 'notification_email')) {
                $table->string('notification_email', 191)->nullable()->after('trigger_tag');
            }
            if (! Schema::hasColumn('backup_runs', 'notify_on_success')) {
                $table->boolean('notify_on_success')->default(true)->after('notification_email');
            }
            if (! Schema::hasColumn('backup_runs', 'notify_on_failure')) {
                $table->boolean('notify_on_failure')->default(true)->after('notify_on_success');
            }
            if (! Schema::hasColumn('backup_runs', 'notification_sent_at')) {
                $table->timestamp('notification_sent_at')->nullable()->after('error_message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('backup_policies', function (Blueprint $table) {
            $columns = array_filter(
                ['notification_email', 'notify_on_success', 'notify_on_failure'],
                fn (string $col) => Schema::hasColumn('backup_policies', $col)
            );
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('backup_runs', function (Blueprint $table) {
            $columns = array_filter(
                ['notification_email', 'notify_on_success', 'notify_on_failure', 'notification_sent_at'],
                fn (string $col) => Schema::hasColumn('backup_runs', $col)
            );
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
