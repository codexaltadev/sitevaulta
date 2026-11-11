<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra probe locations (agents in other regions, managed in the Admin panel). Before a monitor is marked
 * down, the agents re-check it; the majority decides. A failure seen from one place only is "regional".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probe_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('region', 40);
            $table->string('url', 255);
            $table->text('secret');
            $table->json('public_ips')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('health_status', 20)->default('unknown');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->string('last_regional_note', 255)->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('last_regional_note');
        });

        Schema::dropIfExists('probe_locations');
    }
};
