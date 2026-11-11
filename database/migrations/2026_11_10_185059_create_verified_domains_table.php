<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verified_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 255);
            $table->string('token', 64);
            $table->string('verification_method', 20)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verified_domains');
    }
};
