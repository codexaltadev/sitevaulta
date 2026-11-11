<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->after('website_id')->constrained('monitors')->cascadeOnDelete();
            $table->timestamp('expires_at')->nullable()->after('last_status_changed_at');
        });

        Schema::table('plans', function (Blueprint $table): void {
            // null = every monitor type is allowed on this plan.
            $table->json('allowed_monitor_types')->nullable()->after('max_status_pages');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('allowed_monitor_types');
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('expires_at');
        });
    }
};
