<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('type', 20)->default('http');
            $table->string('url', 500)->nullable();
            $table->string('host', 255)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('http_method', 10)->default('GET');
            $table->string('keyword', 255)->nullable();
            $table->boolean('keyword_should_exist')->default(true);
            $table->boolean('keyword_case_sensitive')->default(false);
            $table->boolean('follow_redirects')->default(true);
            $table->unsignedInteger('interval_seconds')->default(300);
            $table->unsignedInteger('timeout_seconds')->default(30);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('last_response_time_ms')->nullable();
            $table->unsignedSmallInteger('last_http_status_code')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_status_changed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['status', 'last_checked_at']);
        });

        Schema::create('monitor_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->unsignedSmallInteger('http_status_code')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('region', 60)->default('primary');
            $table->string('error_message', 500)->nullable();
            $table->timestamp('checked_at');

            $table->index(['monitor_id', 'checked_at']);
        });

        Schema::create('monitor_incidents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('ongoing');
            $table->string('root_cause_type', 30);
            $table->string('root_cause', 500);
            $table->string('region', 60)->default('primary');
            $table->json('request_snapshot')->nullable();
            $table->json('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->boolean('is_excluded_from_reports')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['monitor_id', 'status']);
            $table->index(['workspace_id', 'started_at']);
        });

        Schema::create('monitor_incident_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_incident_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('message', 500);
            $table->string('probe_location', 100)->nullable();
            $table->string('delivery_status', 20)->nullable();
            $table->timestamp('occurred_at');

            $table->index(['monitor_incident_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_incident_events');
        Schema::dropIfExists('monitor_incidents');
        Schema::dropIfExists('monitor_checks');
        Schema::dropIfExists('monitors');
    }
};
