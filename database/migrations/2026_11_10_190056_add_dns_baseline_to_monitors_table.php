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
            // DNS monitors watch several record types and alert when any differs from the captured baseline.
            $table->json('dns_record_types')->nullable()->after('dns_record_type');
            $table->json('dns_baseline')->nullable()->after('dns_expected_value');
        });

        DB::table('monitors')
            ->where('type', 'dns')
            ->orderBy('id')
            ->each(fn (object $monitor) => DB::table('monitors')
                ->where('id', $monitor->id)
                ->update([
                    'dns_record_types' => json_encode([$monitor->dns_record_type ?: 'A']),
                    // Start fresh: the next check captures the baseline.
                    'last_probe_method' => null,
                ]));
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['dns_record_types', 'dns_baseline']);
        });
    }
};
