<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assay_apps', function (Blueprint $table): void {
            $table->unsignedBigInteger('dropped_content_attach_total')->default(0);
        });

        Schema::table('assay_records', function (Blueprint $table): void {
            $table->foreignUuid('run_id')->nullable()->constrained('assay_runs')->cascadeOnDelete();
            $table->text('invocation_id')->nullable();
            $table->string('operation', 32)->nullable();
            $table->string('outcome', 16)->nullable();
        });

        Schema::create('assay_content_attach_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('app_id')->constrained('assay_apps')->cascadeOnDelete();
            $table->uuid('record_id');
            $table->string('status', 16);
            $table->string('reason', 32)->nullable();
            $table->timestampTz('received_at', 6);
            $table->unique(['app_id', 'record_id']);
        });

        Schema::create('assay_pending_content_attaches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('receipt_id')->unique()->constrained('assay_content_attach_receipts')->cascadeOnDelete();
            $table->foreignUuid('app_id')->constrained('assay_apps')->cascadeOnDelete();
            $table->uuid('target_record_id');
            $table->text('invocation_id');
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('received_at', 6);
            $table->timestampTz('expires_at', 6);
            $table->jsonb('content');
            $table->index(['app_id', 'target_record_id']);
            $table->index(['expires_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assay_pending_content_attaches');
        Schema::dropIfExists('assay_content_attach_receipts');

        Schema::table('assay_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('run_id');
            $table->dropColumn(['invocation_id', 'operation', 'outcome']);
        });

        Schema::table('assay_apps', function (Blueprint $table): void {
            $table->dropColumn('dropped_content_attach_total');
        });
    }
};
