<?php

declare(strict_types=1);

use App\Jobs\ProcessUsageEnvelope;
use App\Services\SubjectErasure;
use App\Services\SubjectErasureHasher;
use App\Services\UsageIngestProcessor;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\UuidV7;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\EnvelopeFactory;

function ingestErasureRecords(
    array $records,
    string $app = 'erasure-app',
    string $receivedAt = '2026-10-01T12:00:00.000000+00:00',
): void {
    resolve(UsageIngestProcessor::class)->process($app, $receivedAt, [
        'envelope_id' => (string) UuidV7::generate(),
        'sent_at' => $receivedAt,
        'client' => ['package' => 'artisan-build/assay-client', 'version' => '1.0.0'],
        'sources' => [['driver' => 'laravel-ai', 'package' => 'laravel/ai', 'version' => '1.0.1']],
        'environment' => 'testing',
        'deploy' => null,
        'dropped_transport_total' => 0,
        'dropped_hook_total' => 0,
        'records' => array_map(static function (RecordV1 $record): array {
            $data = $record->toArray();

            if ($record->content !== null) {
                $data['content'] = $record->content->toJson();
            }

            return $data;
        }, $records),
    ]);
}

beforeEach(function (): void {
    Storage::fake('erasure-journal');
    config()->set('assay.erasure.journal_disk', 'erasure-journal');
    config()->set('assay.erasure.journal_prefix', 'journal');
    config()->set('assay.erasure.active_key_version', 'v1');
    config()->set('assay.erasure.keys', ['v1' => 'ERASURE-KEY-CANARY']);
    config()->set('assay.erasure.batch_size', 2);
});

it('erases app-scoped content and held attaches, tombstones metadata, and journals no secrets', function (): void {
    $subject = 'RAW-SUBJECT-CANARY';
    $otherTarget = (string) UuidV7::generate();
    $message = ['role' => 'user', 'text' => 'MESSAGE-CONTENT-CANARY'];
    $hash = hash('sha256', json_encode($message, JSON_THROW_ON_ERROR));
    ingestErasureRecords([
        EnvelopeFactory::record([
            'invocation_id' => 'erased-run',
            'subject' => $subject,
            'capture' => 'full',
            'content' => ['instructions' => 'RECORD-CONTENT-CANARY'],
        ]),
        EnvelopeFactory::record([
            'type' => 'step.start',
            'invocation_id' => 'erased-run',
            'step' => 0,
            'capture' => 'full',
            'content' => ['message_hashes' => [$hash], 'new_messages' => [$hash => $message]],
        ]),
        EnvelopeFactory::attach($otherTarget, 'erased-run', ['instructions' => 'HELD-CONTENT-CANARY']),
    ]);
    ingestErasureRecords([
        EnvelopeFactory::record([
            'invocation_id' => 'other-app-run',
            'subject' => $subject,
            'capture' => 'full',
            'content' => ['instructions' => 'OTHER-APP-CONTENT'],
        ]),
    ], app: 'other-erasure-app');

    $appId = (string) DB::table('assay_apps')->where('app_ref', 'erasure-app')->value('id');
    $result = resolve(SubjectErasure::class)->erase(
        $appId,
        $subject,
        CarbonImmutable::parse('2026-10-01T12:05:00.000000+00:00'),
    );
    $tombstone = (string) DB::table('assay_erasure_records')->where('app_id', $appId)->value('tombstone');
    $journalFiles = Storage::disk('erasure-journal')->allFiles('journal');
    $journal = Storage::disk('erasure-journal')->get($journalFiles[0]);
    $database = json_encode([
        DB::table('assay_erasure_records')->where('app_id', $appId)->get(),
        DB::table('assay_runs')->where('app_id', $appId)->get(),
    ], JSON_THROW_ON_ERROR);

    expect($result)->toBe(['runs_affected' => 1, 'content_rows_deleted' => 4, 'dataset_items_deleted' => 0])
        ->and(DB::table('assay_record_content')->join('assay_runs', 'assay_runs.id', '=', 'assay_record_content.run_id')->where('assay_runs.app_id', $appId)->count())->toBe(0)
        ->and(DB::table('assay_messages')->join('assay_runs', 'assay_runs.id', '=', 'assay_messages.run_id')->where('assay_runs.app_id', $appId)->count())->toBe(0)
        ->and(DB::table('assay_pending_content_attaches')->where('app_id', $appId)->count())->toBe(0)
        ->and(DB::table('assay_content_attach_receipts')->where('app_id', $appId)->where('reason', 'erased_subject')->count())->toBe(1)
        ->and($tombstone)->toStartWith('deleted:')
        ->and(DB::table('assay_runs')->where('app_id', $appId)->value('subject'))->toBe($tombstone)
        ->and(DB::table('assay_runs')->where('subject', $subject)->count())->toBe(1)
        ->and(json_encode(DB::table('assay_record_content')->get(), JSON_THROW_ON_ERROR))->toContain('OTHER-APP-CONTENT')
        ->and($database)->not->toContain($subject, 'ERASURE-KEY-CANARY')
        ->and($journal)->not->toContain($subject, 'ERASURE-KEY-CANARY', 'RECORD-CONTENT-CANARY', 'MESSAGE-CONTENT-CANARY', 'HELD-CONTENT-CANARY');
});

it('enforces pre-cutoff barriers record by record through run associations and content attach', function (): void {
    $subject = 'barrier-subject';
    $targetId = (string) UuidV7::generate();
    ingestErasureRecords([
        EnvelopeFactory::record([
            'record_id' => $targetId,
            'invocation_id' => 'barrier-run',
            'subject' => $subject,
        ]),
    ]);
    $appId = (string) DB::table('assay_apps')->where('app_ref', 'erasure-app')->value('id');
    resolve(SubjectErasure::class)->erase($appId, $subject, CarbonImmutable::parse('2026-10-01T12:05:00.000000+00:00'));
    $before = DB::table('assay_records')->count();
    $rejectedAssociatedId = (string) UuidV7::generate();
    $rejectedRootId = (string) UuidV7::generate();
    $acceptedId = (string) UuidV7::generate();

    ingestErasureRecords([
        EnvelopeFactory::record(['record_id' => $rejectedAssociatedId, 'invocation_id' => 'barrier-run', 'at' => '2026-10-01T12:05:00.000000+00:00']),
        EnvelopeFactory::record(['record_id' => $rejectedRootId, 'invocation_id' => 'rejected-new-run', 'subject' => $subject, 'at' => '2026-10-01T12:04:59.999999+00:00']),
        EnvelopeFactory::record(['record_id' => $acceptedId, 'invocation_id' => 'accepted-new-run', 'subject' => $subject, 'at' => '2026-10-01T12:05:00.000001+00:00']),
        EnvelopeFactory::attach($targetId, 'barrier-run', ['instructions' => 'REJECTED-ATTACH-CANARY'], ['at' => '2026-10-01T12:05:00.000000+00:00']),
    ], receivedAt: '2026-10-01T12:06:00.000000+00:00');

    expect(DB::table('assay_records')->count())->toBe($before + 1)
        ->and(DB::table('assay_records')->where('record_id', $rejectedAssociatedId)->exists())->toBeFalse()
        ->and(DB::table('assay_records')->where('record_id', $rejectedRootId)->exists())->toBeFalse()
        ->and(DB::table('assay_records')->where('record_id', $acceptedId)->exists())->toBeTrue()
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'erased_subject')->count())->toBe(1)
        ->and(json_encode(DB::table('assay_record_content')->get(), JSON_THROW_ON_ERROR))->not->toContain('REJECTED-ATTACH-CANARY');
});

it('prevents an erased target that arrives after its held attach from applying', function (): void {
    $subject = 'held-target-subject';
    $targetId = (string) UuidV7::generate();
    ingestErasureRecords([
        EnvelopeFactory::attach($targetId, 'future-held-run', ['instructions' => 'HELD-ERASE-CANARY']),
        EnvelopeFactory::record(['invocation_id' => 'known-subject-run', 'subject' => $subject]),
    ]);
    $appId = (string) DB::table('assay_apps')->where('app_ref', 'erasure-app')->value('id');
    resolve(SubjectErasure::class)->erase($appId, $subject, CarbonImmutable::parse('2026-10-01T12:05:00.000000+00:00'));

    ingestErasureRecords([
        EnvelopeFactory::record([
            'record_id' => $targetId,
            'invocation_id' => 'future-held-run',
            'subject' => $subject,
            'at' => '2026-10-01T12:04:00.000000+00:00',
        ]),
    ], receivedAt: '2026-10-01T12:06:00.000000+00:00');

    expect(DB::table('assay_records')->where('record_id', $targetId)->exists())->toBeFalse()
        ->and(DB::table('assay_pending_content_attaches')->where('target_record_id', $targetId)->exists())->toBeFalse()
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'erased_subject')->count())->toBe(1)
        ->and(json_encode(DB::table('assay_record_content')->get(), JSON_THROW_ON_ERROR))->not->toContain('HELD-ERASE-CANARY');
});

it('erases descendant content and bars records through inherited invocation subjects', function (): void {
    $subject = 'tree-erasure-subject';
    ingestErasureRecords([
        EnvelopeFactory::record([
            'invocation_id' => 'tree-root',
            'subject' => $subject,
            'capture' => 'full',
            'content' => ['instructions' => 'ROOT-TREE-CONTENT'],
        ]),
        EnvelopeFactory::record([
            'invocation_id' => 'tree-child',
            'parent_invocation_id' => 'tree-root',
            'capture' => 'full',
            'content' => ['instructions' => 'CHILD-TREE-CONTENT'],
        ]),
    ]);
    $appId = (string) DB::table('assay_apps')->where('app_ref', 'erasure-app')->value('id');
    $result = resolve(SubjectErasure::class)->erase($appId, $subject, CarbonImmutable::parse('2026-10-01T12:05:00.000000+00:00'));
    $lateChildId = (string) UuidV7::generate();

    ingestErasureRecords([
        EnvelopeFactory::record([
            'record_id' => $lateChildId,
            'invocation_id' => 'tree-child',
            'at' => '2026-10-01T12:05:00.000000+00:00',
        ]),
    ], receivedAt: '2026-10-01T12:06:00.000000+00:00');

    expect($result)->toBe(['runs_affected' => 2, 'content_rows_deleted' => 2, 'dataset_items_deleted' => 0])
        ->and(DB::table('assay_record_content')->count())->toBe(0)
        ->and(DB::table('assay_runs')->distinct()->pluck('subject')->count())->toBe(1)
        ->and(DB::table('assay_runs')->value('subject'))->toStartWith('deleted:')
        ->and(DB::table('assay_records')->where('record_id', $lateChildId)->exists())->toBeFalse();
});

it('rejects late encrypted queue replay without exposing subject content or key canaries', function (): void {
    $subject = 'LATE-QUEUE-SUBJECT-CANARY';
    $content = 'LATE-QUEUE-CONTENT-CANARY';
    ingestErasureRecords([EnvelopeFactory::record(['invocation_id' => 'late-queue-run', 'subject' => $subject])]);
    $appId = (string) DB::table('assay_apps')->where('app_ref', 'erasure-app')->value('id');
    resolve(SubjectErasure::class)->erase($appId, $subject, CarbonImmutable::parse('2026-10-01T12:05:00.000000+00:00'));
    $job = ProcessUsageEnvelope::fromContract(
        'erasure-app',
        '2026-10-01T12:06:00.000000+00:00',
        EnvelopeFactory::envelope([
            EnvelopeFactory::record([
                'invocation_id' => 'late-queue-run',
                'subject' => $subject,
                'capture' => 'full',
                'content' => ['instructions' => $content],
                'at' => '2026-10-01T12:05:00.000000+00:00',
            ]),
        ]),
    );
    $logs = [];
    Log::listen(static function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event->message.json_encode($event->context, JSON_THROW_ON_ERROR);
    });
    Queue::connection('database')->push($job, queue: 'erasure-late-queue');
    $payload = (string) DB::table('jobs')->where('queue', 'erasure-late-queue')->value('payload');
    /** @var FailedJobProviderInterface $failedJobs */
    $failedJobs = resolve('queue.failer');
    $failedJobs->log('database', 'erasure-late-queue', $payload, new RuntimeException('value-free forced failure'));

    $job->handle(resolve(UsageIngestProcessor::class));
    $job->handle(resolve(UsageIngestProcessor::class));
    $durableQueueSurfaces = $payload.(string) DB::table('failed_jobs')->where('queue', 'erasure-late-queue')->value('payload')
        .(string) DB::table('failed_jobs')->where('queue', 'erasure-late-queue')->value('exception');

    expect(DB::table('assay_records')->where('record_id', $job->envelope['records'][0]['record_id'])->exists())->toBeFalse()
        ->and(DB::table('assay_record_content')->count())->toBe(0)
        ->and($durableQueueSurfaces)->not->toContain($subject, $content, 'ERASURE-KEY-CANARY')
        ->and(implode("\n", $logs))->not->toContain($subject, $content, 'ERASURE-KEY-CANARY');
});

it('retains historical keys for lookup and repeat erasure without changing old tombstones', function (): void {
    $subject = 'rotation-subject';
    ingestErasureRecords([EnvelopeFactory::record(['invocation_id' => 'rotation-run', 'subject' => $subject])]);
    $appId = (string) DB::table('assay_apps')->where('app_ref', 'erasure-app')->value('id');
    resolve(SubjectErasure::class)->erase($appId, $subject, CarbonImmutable::parse('2026-10-01T12:00:00.000000+00:00'));
    $oldTombstone = (string) DB::table('assay_erasure_records')->value('tombstone');

    config()->set('assay.erasure.active_key_version', 'v2');
    config()->set('assay.erasure.keys', ['v2' => 'ROTATED-KEY-CANARY', 'v1' => 'ERASURE-KEY-CANARY']);
    ingestErasureRecords([
        EnvelopeFactory::record([
            'invocation_id' => 'rotation-run',
            'subject' => $subject,
            'at' => '2026-10-01T12:01:00.000000+00:00',
        ]),
    ], receivedAt: '2026-10-01T12:01:00.000000+00:00');
    $result = resolve(SubjectErasure::class)->erase($appId, $subject, CarbonImmutable::parse('2026-10-01T12:02:00.000000+00:00'));
    $tombstoneRepeat = resolve(SubjectErasure::class)->erase($appId, $oldTombstone, CarbonImmutable::parse('2026-10-01T12:02:00.000000+00:00'));

    expect(DB::table('assay_erasure_records')->count())->toBe(1)
        ->and(DB::table('assay_erasure_records')->value('key_version'))->toBe('v1')
        ->and(DB::table('assay_erasure_records')->value('tombstone'))->toBe($oldTombstone)
        ->and(DB::table('assay_runs')->where('id', DB::table('assay_runs')->value('id'))->value('subject'))->toBe($oldTombstone)
        ->and($result['runs_affected'])->toBe(1)
        ->and($tombstoneRepeat)->toBe(['runs_affected' => 0, 'content_rows_deleted' => 0, 'dataset_items_deleted' => 0]);
});

it('uses the frozen HMAC domain and isolates the same subject between apps', function (): void {
    $subject = 'same-subject';
    $expected = hash_hmac('sha256', "assay-subject-erasure\0".$subject, 'ERASURE-KEY-CANARY');
    $identity = resolve(SubjectErasureHasher::class)->active($subject);

    expect($identity)->toBe(['version' => 'v1', 'lookup_key' => $expected, 'tombstone' => 'deleted:'.$expected]);

    ingestErasureRecords([EnvelopeFactory::record(['invocation_id' => 'app-a-run', 'subject' => $subject])], app: 'app-a');
    ingestErasureRecords([EnvelopeFactory::record(['invocation_id' => 'app-b-run', 'subject' => $subject])], app: 'app-b');
    $appA = (string) DB::table('assay_apps')->where('app_ref', 'app-a')->value('id');
    resolve(SubjectErasure::class)->erase($appA, $subject, CarbonImmutable::parse('2026-10-01T12:00:00.000000+00:00'));

    expect(DB::table('assay_runs')->where('app_id', $appA)->value('subject'))->toStartWith('deleted:')
        ->and(DB::table('assay_runs')->join('assay_apps', 'assay_apps.id', '=', 'assay_runs.app_id')->where('assay_apps.app_ref', 'app-b')->value('subject'))->toBe($subject);
});

it('replays journal entries after restore idempotently and contains corrupt entries value-free', function (): void {
    $subject = 'RESTORE-SUBJECT-CANARY';
    $content = 'RESTORE-CONTENT-CANARY';
    $recordId = (string) UuidV7::generate();
    ingestErasureRecords([
        EnvelopeFactory::record([
            'record_id' => $recordId,
            'invocation_id' => 'restore-run',
            'subject' => $subject,
            'capture' => 'full',
            'content' => ['instructions' => $content],
        ]),
    ]);
    $appId = (string) DB::table('assay_apps')->where('app_ref', 'erasure-app')->value('id');
    $runId = (string) DB::table('assay_runs')->value('id');
    $recordRowId = (string) DB::table('assay_records')->where('record_id', $recordId)->value('id');
    resolve(SubjectErasure::class)->erase($appId, $subject, CarbonImmutable::parse('2026-10-01T12:05:00.000000+00:00'));

    DB::table('assay_erasure_records')->delete();
    DB::table('assay_runs')->where('id', $runId)->update(['subject' => $subject]);
    DB::table('assay_record_content')->insert([
        'id' => (string) Str::uuid(),
        'record_id' => $recordRowId,
        'run_id' => $runId,
        'content' => json_encode(['instructions' => $content], JSON_THROW_ON_ERROR),
    ]);

    $filtered = Artisan::call('assay:erasures:reapply', ['--since' => '2999-01-01T00:00:00+00:00', '--limit' => '10']);

    expect($filtered)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('0 journal entries reapplied')
        ->and(DB::table('assay_record_content')->count())->toBe(1)
        ->and(Artisan::call('assay:erasures:reapply', ['--since' => '', '--limit' => '0']))->toBe(Command::INVALID);

    Storage::disk('erasure-journal')->put('journal/corrupt.json', '{RESTORE-CORRUPT-CANARY');

    $first = Artisan::call('assay:erasures:reapply', ['--since' => '2026-10-01T00:00:00+00:00', '--limit' => '10']);
    $firstOutput = Artisan::output();
    $second = Artisan::call('assay:erasures:reapply', ['--since' => '2026-10-01T00:00:00+00:00', '--limit' => '10']);

    expect($first)->toBe(Command::FAILURE)
        ->and($firstOutput)->toContain('1 journal entries reapplied', '1 entries contained')
        ->not->toContain($subject, $content, 'RESTORE-CORRUPT-CANARY')
        ->and(DB::table('assay_record_content')->count())->toBe(0)
        ->and(DB::table('assay_runs')->value('subject'))->toStartWith('deleted:')
        ->and($second)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('0 runs affected', '0 content rows');
});
