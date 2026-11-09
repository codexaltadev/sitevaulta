<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'security_scan_status')) {
                $table->string('security_scan_status', 30)->default('unscanned')->after('last_down_reason');
            }
            if (! Schema::hasColumn('websites', 'security_threats_count')) {
                $table->unsignedInteger('security_threats_count')->default(0)->after('security_scan_status');
            }
            if (! Schema::hasColumn('websites', 'security_scan_report')) {
                $table->json('security_scan_report')->nullable()->after('security_threats_count');
            }
            if (! Schema::hasColumn('websites', 'last_security_scan_at')) {
                $table->timestamp('last_security_scan_at')->nullable()->after('security_scan_report');
            }
            if (! Schema::hasColumn('websites', 'notify_on_malware_detected')) {
                $table->boolean('notify_on_malware_detected')->default(true)->after('notify_on_recovery');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $columns = [
                'security_scan_status',
                'security_threats_count',
                'security_scan_report',
                'last_security_scan_at',
                'notify_on_malware_detected',
            ];

            foreach ($columns as $col) {
                if (Schema::hasColumn('websites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

