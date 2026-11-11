<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedInteger('max_monitors')->default(10)->after('max_retention_days');
            $table->unsignedInteger('min_monitor_interval_seconds')->default(300)->after('max_monitors');
            $table->unsignedInteger('max_status_pages')->default(1)->after('min_monitor_interval_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['max_monitors', 'min_monitor_interval_seconds', 'max_status_pages']);
        });
    }
};
