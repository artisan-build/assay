<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assay_erasure_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('app_id')->constrained('assay_apps')->cascadeOnDelete();
            $table->string('key_version', 32);
            $table->char('lookup_key', 64);
            $table->string('tombstone', 72);
            $table->timestampTz('cutoff_at', 6);
            $table->timestampsTz(6);
            $table->unique(['app_id', 'key_version', 'lookup_key']);
            $table->unique(['app_id', 'tombstone']);
            $table->index(['app_id', 'cutoff_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assay_erasure_records');
    }
};
