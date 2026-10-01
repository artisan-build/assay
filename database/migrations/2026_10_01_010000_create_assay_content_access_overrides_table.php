<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assay_content_access_overrides', function (Blueprint $table): void {
            $table->string('actor_id')->unique();
            $table->string('access');
            $table->string('set_by_actor_id');
            $table->timestampTz('set_at', 6);
            $table->text('reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assay_content_access_overrides');
    }
};
