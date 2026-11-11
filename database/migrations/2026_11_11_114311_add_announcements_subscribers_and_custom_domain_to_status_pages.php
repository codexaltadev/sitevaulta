<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status page upgrades: announcements (maintenance notes / incident updates), email subscribers with
 * double opt-in, and an optional custom domain (status.client.com).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_pages', function (Blueprint $table): void {
            $table->string('custom_domain', 191)->nullable()->unique()->after('slug');
            $table->boolean('allow_subscriptions')->default(true)->after('show_incident_history');
        });

        Schema::create('status_page_announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('info');
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status_page_id', 'created_at']);
        });

        Schema::create('status_page_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('email', 191);
            $table->string('token', 64)->unique();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['status_page_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_subscribers');
        Schema::dropIfExists('status_page_announcements');

        Schema::table('status_pages', function (Blueprint $table): void {
            $table->dropUnique(['custom_domain']);
            $table->dropColumn(['custom_domain', 'allow_subscriptions']);
        });
    }
};
