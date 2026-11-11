<?php

use App\Models\Monitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            // Normalised host of a main monitor (null for extra checks and heartbeats).
            $table->string('target_host', 255)->nullable()->after('host');
        });

        // Backfill; if duplicates already exist, the oldest monitor keeps the host so the index can be built.
        $seen = [];

        Monitor::query()->orderBy('id')->each(function (Monitor $monitor) use (&$seen): void {
            $host = $monitor->computeTargetHost();
            $key = "{$monitor->workspace_id}|{$host}";

            if ($host === null || isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            DB::table('monitors')->where('id', $monitor->id)->update(['target_host' => $host]);
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'target_host']);
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropUnique(['workspace_id', 'target_host']);
            $table->dropColumn('target_host');
        });
    }
};
