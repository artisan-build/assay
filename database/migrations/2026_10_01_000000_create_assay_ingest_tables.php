<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assay_apps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('app_ref')->unique();
            $table->unsignedBigInteger('dropped_transport_total')->default(0);
            $table->unsignedBigInteger('dropped_hook_total')->default(0);
            $table->timestampsTz(6);
        });

        Schema::create('assay_envelopes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('app_id')->constrained('assay_apps')->cascadeOnDelete();
            $table->uuid('envelope_id');
            $table->timestampTz('sent_at', 6);
            $table->timestampTz('received_at', 6);
            $table->text('client_package');
            $table->text('client_version');
            $table->text('environment');
            $table->text('deploy')->nullable();
            $table->unique(['app_id', 'envelope_id']);
        });

        Schema::create('assay_envelope_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('envelope_id')->constrained('assay_envelopes')->cascadeOnDelete();
            $table->text('driver');
            $table->text('package');
            $table->text('version');
        });

        Schema::create('assay_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('app_id')->constrained('assay_apps')->cascadeOnDelete();
            $table->foreignUuid('envelope_id')->constrained('assay_envelopes')->cascadeOnDelete();
            $table->uuid('record_id');
            $table->text('source');
            $table->string('type', 32);
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('received_at', 6);
            $table->unique(['app_id', 'record_id']);
        });

        Schema::create('assay_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('app_id')->constrained('assay_apps')->cascadeOnDelete();
            $table->text('invocation_id');
            $table->string('operation', 32)->nullable();
            $table->uuid('parent_run_id')->nullable();
            $table->text('parent_tool_invocation_id')->nullable();
            $table->text('source')->nullable();
            $table->string('capture', 16)->nullable();
            $table->boolean('sampled')->nullable();
            $table->text('subject')->nullable();
            $table->text('environment')->nullable();
            $table->text('deploy')->nullable();
            $table->text('client_package')->nullable();
            $table->text('client_version')->nullable();
            $table->text('agent')->nullable();
            $table->text('provider')->nullable();
            $table->text('requested_model')->nullable();
            $table->text('responded_model')->nullable();
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('ended_at', 6)->nullable();
            $table->timestampTz('started_received_at', 6)->nullable();
            $table->timestampTz('ended_received_at', 6)->nullable();
            $table->string('outcome', 16)->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('failure_class')->nullable();
            $table->string('finish_reason', 32)->nullable();
            $table->decimal('duration_ms', 30, 3)->nullable();
            $table->string('failure_capture', 16)->nullable();
            $table->jsonb('replay_inputs_omitted')->nullable();
            $table->unsignedInteger('terminal_attempt')->nullable();
            $table->boolean('checksum_mismatch')->default(false);
            $table->unique(['app_id', 'invocation_id']);
            $table->index(['status', 'started_received_at']);
        });

        Schema::table('assay_runs', function (Blueprint $table): void {
            $table->foreign('parent_run_id')->references('id')->on('assay_runs')->nullOnDelete();
        });

        Schema::create('assay_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('assay_runs')->cascadeOnDelete();
            $table->unsignedInteger('ordinal');
            $table->text('agent')->nullable();
            $table->text('provider')->nullable();
            $table->text('requested_model')->nullable();
            $table->text('responded_model')->nullable();
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('ended_at', 6)->nullable();
            $table->boolean('terminal')->default(false);
            $table->unique(['run_id', 'ordinal']);
        });

        Schema::create('assay_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('record_id')->unique()->constrained('assay_records')->cascadeOnDelete();
            $table->foreignUuid('run_id')->constrained('assay_runs')->cascadeOnDelete();
            $table->foreignUuid('attempt_id')->constrained('assay_attempts')->cascadeOnDelete();
            $table->string('event', 16);
            $table->unsignedInteger('step_number');
            $table->timestampTz('occurred_at', 6);
            $table->decimal('duration_ms', 30, 3)->nullable();
            $table->text('agent')->nullable();
            $table->text('provider')->nullable();
            $table->text('requested_model')->nullable();
            $table->text('responded_model')->nullable();
            $table->string('finish_reason', 32)->nullable();
            $table->text('failure_class')->nullable();
            $table->index(['run_id', 'attempt_id', 'step_number']);
        });

        Schema::create('assay_tool_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('record_id')->unique()->constrained('assay_records')->cascadeOnDelete();
            $table->foreignUuid('run_id')->constrained('assay_runs')->cascadeOnDelete();
            $table->foreignUuid('attempt_id')->constrained('assay_attempts')->cascadeOnDelete();
            $table->string('event', 16);
            $table->unsignedInteger('step_number')->nullable();
            $table->text('tool_invocation_id');
            $table->text('tool')->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->decimal('duration_ms', 30, 3)->nullable();
            $table->string('outcome', 16)->nullable();
            $table->string('approval', 16)->nullable();
            $table->text('failure_class')->nullable();
            $table->index(['run_id', 'tool_invocation_id']);
        });

        Schema::create('assay_run_failovers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('record_id')->unique()->constrained('assay_records')->cascadeOnDelete();
            $table->foreignUuid('app_id')->constrained('assay_apps')->cascadeOnDelete();
            $table->foreignUuid('run_id')->nullable()->constrained('assay_runs')->cascadeOnDelete();
            $table->unsignedInteger('attempt')->nullable();
            $table->text('provider');
            $table->text('requested_model');
            $table->text('failure_class')->nullable();
            $table->timestampTz('occurred_at', 6);
        });

        Schema::create('assay_usage_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('record_id')->constrained('assay_records')->cascadeOnDelete();
            $table->foreignUuid('run_id')->constrained('assay_runs')->cascadeOnDelete();
            $table->foreignUuid('attempt_id')->nullable()->constrained('assay_attempts')->cascadeOnDelete();
            $table->string('source', 24);
            $table->string('metric', 40);
            $table->decimal('value', 38, 12);
            $table->text('provider')->nullable();
            $table->text('requested_model')->nullable();
            $table->text('responded_model')->nullable();
            $table->unique(['record_id', 'metric']);
            $table->index(['run_id', 'attempt_id', 'source', 'metric']);
        });

        DB::statement('ALTER TABLE assay_usage_metrics ALTER COLUMN value TYPE NUMERIC');
    }

    public function down(): void
    {
        Schema::dropIfExists('assay_usage_metrics');
        Schema::dropIfExists('assay_run_failovers');
        Schema::dropIfExists('assay_tool_events');
        Schema::dropIfExists('assay_steps');
        Schema::dropIfExists('assay_attempts');
        Schema::dropIfExists('assay_runs');
        Schema::dropIfExists('assay_records');
        Schema::dropIfExists('assay_envelope_sources');
        Schema::dropIfExists('assay_envelopes');
        Schema::dropIfExists('assay_apps');
    }
};
