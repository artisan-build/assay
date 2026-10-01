<?php

declare(strict_types=1);

use App\Services\UsageIngestProcessor;
use ArtisanBuild\AssayContracts\UuidV7;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('uses PostgreSQL uniqueness as the concurrent envelope and record idempotency arbiter', function (): void {
    expect(Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]))->toBe(0);

    $recordId = (string) UuidV7::generate();
    $processes = [
        new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-ingest.php'), (string) UuidV7::generate(), $recordId, '10', '2']),
        new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-ingest.php'), (string) UuidV7::generate(), $recordId, '3', '8']),
    ];

    foreach ($processes as $process) {
        $process->start();
    }

    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    }

    $app = DB::table('assay_apps')->sole();

    expect(DB::table('assay_apps')->count())->toBe(1)
        ->and(DB::table('assay_envelopes')->count())->toBe(2)
        ->and(DB::table('assay_records')->count())->toBe(1)
        ->and(DB::table('assay_runs')->count())->toBe(1)
        ->and($app->dropped_transport_total)->toBe(10)
        ->and($app->dropped_hook_total)->toBe(8);
});

it('serializes distinct content attaches on the target so the first accepted attach wins', function (): void {
    expect(Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]))->toBe(0);

    $targetId = (string) UuidV7::generate();
    resolve(UsageIngestProcessor::class)->process('concurrent-attach-app', '2026-10-01T12:00:00.000000+00:00', [
        'envelope_id' => (string) UuidV7::generate(),
        'sent_at' => '2026-10-01T12:00:00.000000+00:00',
        'client' => ['package' => 'artisan-build/assay-client', 'version' => '1.0.0'],
        'sources' => [['driver' => 'laravel-ai', 'package' => 'laravel/ai', 'version' => '1.0.1']],
        'environment' => 'testing',
        'deploy' => null,
        'dropped_transport_total' => 0,
        'dropped_hook_total' => 0,
        'records' => [[
            'record_id' => $targetId,
            'source' => 'laravel-ai',
            'type' => 'run.start',
            'operation' => 'agent',
            'invocation_id' => 'concurrent-attach-run',
            'attempt' => 1,
            'at' => '2026-10-01T12:00:00.000000+00:00',
            'capture' => 'usage',
            'sampled' => false,
        ]],
    ]);
    $processes = [
        new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-content-attach.php'), (string) UuidV7::generate(), (string) UuidV7::generate(), $targetId, 'RACE-CONTENT-A']),
        new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-content-attach.php'), (string) UuidV7::generate(), (string) UuidV7::generate(), $targetId, 'RACE-CONTENT-B']),
    ];

    foreach ($processes as $process) {
        $process->start();
    }

    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    }

    $content = (string) DB::table('assay_record_content')->sole()->content;

    expect(DB::table('assay_content_attach_receipts')->where('status', 'accepted')->count())->toBe(1)
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'target_has_content')->count())->toBe(1)
        ->and(substr_count($content, 'RACE-CONTENT-'))->toBe(1);
});
