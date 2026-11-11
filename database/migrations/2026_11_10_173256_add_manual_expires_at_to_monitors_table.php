<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            // Entered by the user for registries that publish no RDAP/WHOIS expiry (e.g. .bd).
            $table->date('manual_expires_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('manual_expires_at');
        });
    }
};
