<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('schedule_type', 20)->default('one_time');
            $table->timestamp('starts_at')->nullable();
            $table->string('start_time', 5)->nullable();
            $table->json('days_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['workspace_id', 'is_active']);
        });

        Schema::create('maintenance_window_monitor', function (Blueprint $table): void {
            $table->foreignId('maintenance_window_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();

            $table->primary(['maintenance_window_id', 'monitor_id']);
            $table->index('monitor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_window_monitor');
        Schema::dropIfExists('maintenance_windows');
    }
};
