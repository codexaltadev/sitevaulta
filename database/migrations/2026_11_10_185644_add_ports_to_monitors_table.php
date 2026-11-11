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
            // Port monitors check several TCP ports on one host; `port` keeps the first one (and SSL's port).
            $table->json('ports')->nullable()->after('port');
        });

        DB::table('monitors')
            ->where('type', 'port')
            ->whereNotNull('port')
            ->orderBy('id')
            ->each(fn (object $monitor) => DB::table('monitors')
                ->where('id', $monitor->id)
                ->update(['ports' => json_encode([(int) $monitor->port])]));
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('ports');
        });
    }
};
