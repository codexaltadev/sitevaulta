<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('slug', 80)->unique();
            $table->string('description', 500)->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_published')->default(true);
            $table->boolean('show_uptime_bars')->default(true);
            $table->boolean('show_incident_history')->default(true);
            $table->timestamps();
        });

        Schema::create('monitor_status_page', function (Blueprint $table): void {
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();

            $table->primary(['status_page_id', 'monitor_id']);
            $table->index('monitor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_status_page');
        Schema::dropIfExists('status_pages');
    }
};
