<?php

declare(strict_types=1);

use ArtisanBuild\AssayContracts\UuidV7;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('uses PostgreSQL uniqueness as the concurrent envelope and record idempotency arbiter', function (): void {
    expect(Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]))->toBe(0);

    $recordId = (string) UuidV7::generate();
    $processes = [
        new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-ingest.php'), (string) UuidV7::generate(), $recordId]),
        new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-ingest.php'), (string) UuidV7::generate(), $recordId]),
    ];

    foreach ($processes as $process) {
        $process->start();
    }

    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    }

    expect(DB::table('assay_apps')->count())->toBe(1)
        ->and(DB::table('assay_envelopes')->count())->toBe(2)
        ->and(DB::table('assay_records')->count())->toBe(1)
        ->and(DB::table('assay_runs')->count())->toBe(1);
});
