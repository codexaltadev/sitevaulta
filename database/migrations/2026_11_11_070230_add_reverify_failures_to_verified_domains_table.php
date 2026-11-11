<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verified domains are re-checked weekly; consecutive failures revoke the verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verified_domains', function (Blueprint $table): void {
            $table->unsignedTinyInteger('reverify_failures')->default(0)->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('verified_domains', function (Blueprint $table): void {
            $table->dropColumn('reverify_failures');
        });
    }
};
