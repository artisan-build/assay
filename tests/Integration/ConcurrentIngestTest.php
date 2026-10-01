<?php

declare(strict_types=1);

use App\Services\SubjectErasure;
use App\Services\SubjectErasureBarrier;
use App\Services\UsageIngestProcessor;
use ArtisanBuild\AssayContracts\UuidV7;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;

function ingestDescendantAttachTarget(): array
{
    $targetId = (string) UuidV7::generate();
    $subject = 'descendant-concurrent-erasure-subject';
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
            'record_id' => (string) UuidV7::generate(),
            'source' => 'laravel-ai',
            'type' => 'run.start',
            'operation' => 'agent',
            'invocation_id' => 'descendant-root-run',
            'attempt' => 1,
            'at' => '2026-10-01T12:00:00.000000+00:00',
            'capture' => 'usage',
            'sampled' => false,
            'subject' => $subject,
        ], [
            'record_id' => $targetId,
            'source' => 'laravel-ai',
            'type' => 'run.start',
            'operation' => 'agent',
            'invocation_id' => 'descendant-child-run',
            'parent_invocation_id' => 'descendant-root-run',
            'attempt' => 1,
            'at' => '2026-10-01T12:00:00.000000+00:00',
            'capture' => 'usage',
            'sampled' => false,
        ]],
    ]);

    return [
        'app_id' => (string) DB::table('assay_apps')->where('app_ref', 'concurrent-attach-app')->value('id'),
        'child_run_id' => (string) DB::table('assay_runs')->where('invocation_id', 'descendant-child-run')->value('id'),
        'subject' => $subject,
        'target_id' => $targetId,
    ];
}

function descendantAttachProcess(string $targetId, string $content): Process
{
    return new Process([
        PHP_BINARY,
        base_path('tests/Fixtures/concurrent-content-attach.php'),
        (string) UuidV7::generate(),
        (string) UuidV7::generate(),
        $targetId,
        $content,
        'descendant-child-run',
    ]);
}

function ingestDescendantAttach(string $targetId, string $content): void
{
    resolve(UsageIngestProcessor::class)->process('concurrent-attach-app', '2026-10-01T12:01:00.000000+00:00', [
        'envelope_id' => (string) UuidV7::generate(),
        'sent_at' => '2026-10-01T12:01:00.000000+00:00',
        'client' => ['package' => 'artisan-build/assay-client', 'version' => '1.0.0'],
        'sources' => [['driver' => 'laravel-ai', 'package' => 'laravel/ai', 'version' => '1.0.1']],
        'environment' => 'testing',
        'deploy' => null,
        'dropped_transport_total' => 0,
        'dropped_hook_total' => 0,
        'records' => [[
            'record_id' => (string) UuidV7::generate(),
            'type' => 'content.attach',
            'target_record_id' => $targetId,
            'invocation_id' => 'descendant-child-run',
            'at' => '2026-10-01T12:01:00.000000+00:00',
            'capture' => 'full',
            'content' => json_encode(['instructions' => $content], JSON_THROW_ON_ERROR),
        ]],
    ]);
}

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

it('leaves no content when subject erasure races content attach application', function (): void {
    expect(Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]))->toBe(0);
    Storage::disk('local')->deleteDirectory('assay/erasures');
    $targetId = (string) UuidV7::generate();
    $subject = 'concurrent-erasure-subject';
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
            'subject' => $subject,
        ]],
    ]);
    $appId = (string) DB::table('assay_apps')->where('app_ref', 'concurrent-attach-app')->value('id');
    $processes = [
        new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-content-attach.php'), (string) UuidV7::generate(), (string) UuidV7::generate(), $targetId, 'ERASURE-RACE-CONTENT']),
        new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-erasure.php'), $appId, $subject, '2026-10-01T12:01:00.000000+00:00']),
    ];

    foreach ($processes as $process) {
        $process->start();
    }

    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    }

    expect(DB::table('assay_record_content')->count())->toBe(0)
        ->and(DB::table('assay_runs')->value('subject'))->toStartWith('deleted:')
        ->and(DB::table('assay_erasure_records')->count())->toBe(1)
        ->and(DB::table('assay_content_attach_receipts')->count())->toBe(1);

    Storage::disk('local')->deleteDirectory('assay/erasures');
});

it('serializes erasure first against a subjectless descendant attach', function (): void {
    expect(Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]))->toBe(0);
    Storage::disk('local')->deleteDirectory('assay/erasures');
    $target = ingestDescendantAttachTarget();

    DB::beginTransaction();
    resolve(SubjectErasureBarrier::class)->lock($target['app_id'], $target['subject']);
    resolve(SubjectErasure::class)->erase(
        $target['app_id'],
        $target['subject'],
        CarbonImmutable::parse('2026-10-01T12:01:00.000000+00:00'),
    );
    $attach = descendantAttachProcess($target['target_id'], 'ERASURE-FIRST-DESCENDANT-CONTENT');
    $attach->start();
    Sleep::usleep(300_000);

    expect($attach->isRunning())->toBeTrue('The descendant attach did not wait for the erasure subject lock.');

    DB::commit();
    $attach->wait();

    expect($attach->isSuccessful())->toBeTrue($attach->getErrorOutput())
        ->and(DB::table('assay_record_content')->count())->toBe(0)
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'erased_subject')->count())->toBe(1);

    Storage::disk('local')->deleteDirectory('assay/erasures');
});

it('serializes a subjectless descendant attach first and erasure removes its content', function (): void {
    expect(Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]))->toBe(0);
    Storage::disk('local')->deleteDirectory('assay/erasures');
    $target = ingestDescendantAttachTarget();

    DB::beginTransaction();
    expect(resolve(SubjectErasureBarrier::class)->allowsRun(
        $target['app_id'],
        $target['child_run_id'],
        CarbonImmutable::parse('2026-10-01T12:01:00.000000+00:00'),
    ))->toBeTrue();
    $erasure = new Process([
        PHP_BINARY,
        base_path('tests/Fixtures/concurrent-erasure.php'),
        $target['app_id'],
        $target['subject'],
        '2026-10-01T12:01:00.000000+00:00',
    ]);
    $erasure->start();
    Sleep::usleep(300_000);

    expect($erasure->isRunning())->toBeTrue('Erasure did not wait for the descendant attach subject lock.');

    ingestDescendantAttach($target['target_id'], 'ATTACH-FIRST-DESCENDANT-CONTENT');
    DB::commit();
    $erasure->wait();

    expect($erasure->isSuccessful())->toBeTrue($erasure->getErrorOutput())
        ->and(DB::table('assay_record_content')->count())->toBe(0)
        ->and(DB::table('assay_runs')->where('invocation_id', 'descendant-child-run')->value('subject'))->toStartWith('deleted:');

    Storage::disk('local')->deleteDirectory('assay/erasures');
});
