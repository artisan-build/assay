<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assay_runs', function (Blueprint $table): void {
            $table->boolean('content_incomplete')->default(false);
        });

        Schema::create('assay_record_content', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('record_id')->unique()->constrained('assay_records')->cascadeOnDelete();
            $table->foreignUuid('run_id')->constrained('assay_runs')->cascadeOnDelete();
            $table->jsonb('content');
            $table->index('run_id');
        });

        Schema::create('assay_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('assay_runs')->cascadeOnDelete();
            $table->char('hash', 64);
            $table->jsonb('body');
            $table->unique(['run_id', 'hash']);
        });

        Schema::create('assay_message_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('record_id')->constrained('assay_records')->cascadeOnDelete();
            $table->foreignUuid('run_id')->constrained('assay_runs')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->char('hash', 64);
            $table->unique(['record_id', 'position']);
            $table->index(['run_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assay_message_references');
        Schema::dropIfExists('assay_messages');
        Schema::dropIfExists('assay_record_content');

        Schema::table('assay_runs', function (Blueprint $table): void {
            $table->dropColumn('content_incomplete');
        });
    }
};
