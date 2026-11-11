<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website HTTP uptime is owned by the website's linked monitor; these copies of the monitor's last
 * result are read through Website accessors instead. Server health and SSL columns stay on websites.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $columns = ['uptime_status', 'last_http_status_code', 'last_response_time_ms', 'last_uptime_checked_at', 'last_down_reason'];

    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropIndex(['uptime_status']);
            $table->dropColumn($this->columns);
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->string('uptime_status', 30)->default('unknown')->index()->after('uptime_monitoring_enabled');
            $table->unsignedSmallInteger('last_http_status_code')->nullable()->after('uptime_status');
            $table->unsignedInteger('last_response_time_ms')->nullable()->after('last_http_status_code');
            $table->timestamp('last_uptime_checked_at')->nullable()->after('server_live_status');
            $table->string('last_down_reason', 255)->nullable()->after('last_uptime_checked_at');
        });
    }
};
