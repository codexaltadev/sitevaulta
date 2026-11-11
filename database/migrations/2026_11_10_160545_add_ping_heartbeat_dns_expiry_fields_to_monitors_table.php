<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->string('heartbeat_token', 64)->nullable()->unique()->after('follow_redirects');
            $table->unsignedInteger('heartbeat_grace_seconds')->default(300)->after('heartbeat_token');
            $table->timestamp('last_heartbeat_at')->nullable()->after('heartbeat_grace_seconds');
            $table->string('dns_record_type', 10)->nullable()->after('last_heartbeat_at');
            $table->string('dns_expected_value', 255)->nullable()->after('dns_record_type');
            $table->unsignedSmallInteger('expiry_threshold_days')->default(14)->after('dns_expected_value');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropUnique(['heartbeat_token']);
            $table->dropColumn([
                'heartbeat_token',
                'heartbeat_grace_seconds',
                'last_heartbeat_at',
                'dns_record_type',
                'dns_expected_value',
                'expiry_threshold_days',
            ]);
        });
    }
};
