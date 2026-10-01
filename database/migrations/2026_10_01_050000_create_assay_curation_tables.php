<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assay_run_flags', function (Blueprint $table): void {
            $table->foreignUuid('run_id')->primary()->constrained('assay_runs')->cascadeOnDelete();
            $table->jsonb('labels');
            $table->string('rating', 8)->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('assay_datasets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('name');
            $table->unsignedInteger('retention_days')->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('assay_dataset_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('dataset_id')->constrained('assay_datasets')->cascadeOnDelete();
            $table->foreignUuid('app_id')->constrained('assay_apps')->cascadeOnDelete();
            $table->foreignUuid('source_run_id')->nullable()->constrained('assay_runs')->nullOnDelete();
            $table->string('subject_key_version', 32)->nullable();
            $table->string('subject_tombstone', 72)->nullable();
            $table->timestampTz('source_occurred_at', 6);
            $table->jsonb('snapshot');
            $table->timestampTz('added_at', 6);
            $table->timestampTz('expires_at', 6)->nullable();
            $table->unique(['dataset_id', 'source_run_id']);
            $table->index(['expires_at', 'id']);
            $table->index(['app_id', 'subject_tombstone']);
        });

        Schema::create('assay_export_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('dataset_id')->constrained('assay_datasets')->cascadeOnDelete();
            $table->text('requested_by');
            $table->timestampTz('requested_at', 6);
            $table->index('requested_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assay_export_requests');
        Schema::dropIfExists('assay_dataset_items');
        Schema::dropIfExists('assay_datasets');
        Schema::dropIfExists('assay_run_flags');
    }
};
