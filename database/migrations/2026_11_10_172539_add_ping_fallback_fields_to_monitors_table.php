<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            // Ping monitors fall back to a TCP connect on 443/80 when ICMP is blocked, unless strict.
            $table->boolean('ping_strict_icmp')->default(false)->after('expiry_threshold_days');
            // How the last check was measured: "icmp", "tcp:443", "tcp:80" (ping monitors only).
            $table->string('last_probe_method', 20)->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['ping_strict_icmp', 'last_probe_method']);
        });
    }
};
