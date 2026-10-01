<?php

declare(strict_types=1);

use App\Services\ContentAttachAdmission;
use App\Services\ContentStoreRegistry;
use App\Services\UsageIngestProcessor;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\UuidV7;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnvelopeFactory;

function ingestAttachRecords(
    array $records,
    string $app = 'attach-app',
    string $receivedAt = '2026-10-01T12:00:00.000000+00:00',
): void {
    resolve(UsageIngestProcessor::class)->process($app, $receivedAt, [
        'envelope_id' => (string) UuidV7::generate(),
        'sent_at' => '2026-10-01T11:59:59.000000+00:00',
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

it('attaches content only to an existing target without changing any projection or usage fact', function (): void {
    $targetId = (string) UuidV7::generate();
    $target = EnvelopeFactory::record([
        'record_id' => $targetId,
        'type' => 'step.end',
        'invocation_id' => 'target-first',
        'step' => 0,
        'usage' => ['input_tokens' => 7],
        'duration_ms' => 12.5,
    ]);
    ingestAttachRecords([$target]);
    $before = [
        'records' => DB::table('assay_records')->count(),
        'runs' => DB::table('assay_runs')->get()->toJson(),
        'attempts' => DB::table('assay_attempts')->get()->toJson(),
        'steps' => DB::table('assay_steps')->get()->toJson(),
        'tools' => DB::table('assay_tool_events')->get()->toJson(),
        'failovers' => DB::table('assay_run_failovers')->get()->toJson(),
        'usage' => DB::table('assay_usage_metrics')->get()->toJson(),
    ];

    ingestAttachRecords([
        EnvelopeFactory::attach($targetId, 'target-first', ['output_text' => 'ATTACHED-CONTENT']),
    ], receivedAt: '2026-10-01T12:01:00.000000+00:00');

    $stored = DB::table('assay_record_content')
        ->join('assay_records', 'assay_records.id', '=', 'assay_record_content.record_id')
        ->where('assay_records.record_id', $targetId)
        ->sole();

    expect($stored->content)->toContain('ATTACHED-CONTENT')
        ->and(DB::table('assay_records')->count())->toBe($before['records'])
        ->and(DB::table('assay_runs')->get()->toJson())->toBe($before['runs'])
        ->and(DB::table('assay_attempts')->get()->toJson())->toBe($before['attempts'])
        ->and(DB::table('assay_steps')->get()->toJson())->toBe($before['steps'])
        ->and(DB::table('assay_tool_events')->get()->toJson())->toBe($before['tools'])
        ->and(DB::table('assay_run_failovers')->get()->toJson())->toBe($before['failovers'])
        ->and(DB::table('assay_usage_metrics')->get()->toJson())->toBe($before['usage'])
        ->and(DB::table('assay_content_attach_receipts')->sole()->status)->toBe('accepted');
});

it('holds an early attach, applies it when the target arrives, and expires exactly at the stale boundary', function (): void {
    config()->set('assay.stale_after_minutes', 30);
    $appliedTarget = (string) UuidV7::generate();
    $expiredTarget = (string) UuidV7::generate();
    ingestAttachRecords([
        EnvelopeFactory::attach($appliedTarget, 'held-applied', ['instructions' => 'HELD-APPLIED']),
        EnvelopeFactory::attach($appliedTarget, 'held-applied', ['instructions' => 'HELD-SECOND']),
        EnvelopeFactory::attach($expiredTarget, 'held-expired', ['instructions' => 'HELD-EXPIRED']),
    ]);

    expect(DB::table('assay_pending_content_attaches')->count())->toBe(3);

    ingestAttachRecords([
        EnvelopeFactory::record(['record_id' => $appliedTarget, 'invocation_id' => 'held-applied']),
    ], receivedAt: '2026-10-01T12:29:59.999999+00:00');
    ingestAttachRecords([
        EnvelopeFactory::record(['record_id' => $expiredTarget, 'invocation_id' => 'held-expired']),
    ], receivedAt: '2026-10-01T12:30:00.000000+00:00');

    expect(DB::table('assay_pending_content_attaches')->count())->toBe(0)
        ->and(DB::table('assay_record_content')->count())->toBe(1)
        ->and((string) DB::table('assay_record_content')->value('content'))->toContain('HELD-APPLIED')
        ->not->toContain('HELD-SECOND', 'HELD-EXPIRED')
        ->and(DB::table('assay_apps')->value('dropped_content_attach_total'))->toBe(1)
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'target_has_content')->count())->toBe(1)
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'orphan_expired')->count())->toBe(1);
});

it('bounds expired same-target reconciliation when the target arrives at the stale boundary', function (): void {
    config()->set('assay.stale_after_minutes', 30);
    config()->set('assay.content_attach.batch_size', 1);
    $targetId = (string) UuidV7::generate();

    ingestAttachRecords([
        EnvelopeFactory::attach($targetId, 'bounded-expired', ['instructions' => 'EXPIRED-FIRST']),
        EnvelopeFactory::attach($targetId, 'bounded-expired', ['instructions' => 'EXPIRED-SECOND']),
        EnvelopeFactory::attach($targetId, 'bounded-expired', ['instructions' => 'EXPIRED-THIRD']),
    ]);
    ingestAttachRecords([
        EnvelopeFactory::record(['record_id' => $targetId, 'invocation_id' => 'bounded-expired']),
    ], receivedAt: '2026-10-01T12:30:00.000000+00:00');

    expect(DB::table('assay_pending_content_attaches')->count())->toBe(2)
        ->and(DB::table('assay_apps')->value('dropped_content_attach_total'))->toBe(1)
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'orphan_expired')->count())->toBe(1)
        ->and(DB::table('assay_content_attach_receipts')->where('status', 'pending')->count())->toBe(2);

    $admission = resolve(ContentAttachAdmission::class);
    $first = $admission->reconcilePending(CarbonImmutable::parse('2026-10-01T12:30:00.000000+00:00'));
    $pendingAfterFirst = DB::table('assay_pending_content_attaches')->count();
    $second = $admission->reconcilePending(CarbonImmutable::parse('2026-10-01T12:30:00.000000+00:00'));

    expect($first)->toBe(['applied' => 0, 'dropped' => 1])
        ->and($pendingAfterFirst)->toBe(1)
        ->and($second)->toBe(['applied' => 0, 'dropped' => 1])
        ->and(DB::table('assay_pending_content_attaches')->count())->toBe(0)
        ->and(DB::table('assay_apps')->value('dropped_content_attach_total'))->toBe(3)
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'orphan_expired')->count())->toBe(3);
});

it('bounds unexpired same-target reconciliation without weakening first-wins behavior', function (): void {
    config()->set('assay.stale_after_minutes', 30);
    config()->set('assay.content_attach.batch_size', 1);
    $targetId = (string) UuidV7::generate();

    ingestAttachRecords([
        EnvelopeFactory::attach($targetId, 'bounded-first-wins', ['instructions' => 'BOUNDED-FIRST']),
        EnvelopeFactory::attach($targetId, 'bounded-first-wins', ['instructions' => 'BOUNDED-SECOND']),
        EnvelopeFactory::attach($targetId, 'bounded-first-wins', ['instructions' => 'BOUNDED-THIRD']),
    ]);
    ingestAttachRecords([
        EnvelopeFactory::record(['record_id' => $targetId, 'invocation_id' => 'bounded-first-wins']),
    ], receivedAt: '2026-10-01T12:29:59.999999+00:00');

    expect(DB::table('assay_pending_content_attaches')->count())->toBe(2)
        ->and(DB::table('assay_content_attach_receipts')->where('status', 'accepted')->count())->toBe(1)
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'target_has_content')->count())->toBe(0)
        ->and((string) DB::table('assay_record_content')->sole()->content)->toContain('BOUNDED-FIRST')
        ->not->toContain('BOUNDED-SECOND', 'BOUNDED-THIRD');

    $admission = resolve(ContentAttachAdmission::class);
    $first = $admission->reconcilePending(CarbonImmutable::parse('2026-10-01T12:29:59.999999+00:00'));
    $pendingAfterFirst = DB::table('assay_pending_content_attaches')->count();
    $second = $admission->reconcilePending(CarbonImmutable::parse('2026-10-01T12:29:59.999999+00:00'));

    expect($first)->toBe(['applied' => 0, 'dropped' => 0])
        ->and($pendingAfterFirst)->toBe(1)
        ->and($second)->toBe(['applied' => 0, 'dropped' => 0])
        ->and(DB::table('assay_pending_content_attaches')->count())->toBe(0)
        ->and(DB::table('assay_content_attach_receipts')->where('status', 'accepted')->count())->toBe(1)
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'target_has_content')->count())->toBe(2)
        ->and((string) DB::table('assay_record_content')->sole()->content)->toContain('BOUNDED-FIRST')
        ->not->toContain('BOUNDED-SECOND', 'BOUNDED-THIRD');
});

it('drains expired orphan attaches in bounded order and counts every drop', function (): void {
    config()->set('assay.stale_after_minutes', 30);
    config()->set('assay.content_attach.batch_size', 2);

    foreach (range(1, 3) as $index) {
        ingestAttachRecords([
            EnvelopeFactory::attach((string) UuidV7::generate(), "orphan-{$index}", ['instructions' => "ORPHAN-{$index}"]),
        ], receivedAt: "2026-10-01T12:0{$index}:00.000000+00:00");
    }

    CarbonImmutable::setTestNow('2026-10-01T13:00:00.000000+00:00');

    try {
        expect(Artisan::call('assay:reconcile-content-attaches', ['--limit' => 'invalid']))->toBe(Command::INVALID)
            ->and(Artisan::output())->toContain('The --limit option must be a positive integer.')
            ->and(DB::table('assay_pending_content_attaches')->count())->toBe(3)
            ->and(Artisan::call('assay:reconcile-content-attaches', ['--limit' => '2']))->toBe(Command::SUCCESS)
            ->and(Artisan::output())->toContain('0 held attaches applied; 2 expired orphans dropped.')
            ->and(DB::table('assay_pending_content_attaches')->count())->toBe(1);
    } finally {
        CarbonImmutable::setTestNow();
    }

    $second = resolve(ContentAttachAdmission::class)
        ->reconcilePending(CarbonImmutable::parse('2026-10-01T13:00:00.000000+00:00'));

    expect($second)->toBe(['applied' => 0, 'dropped' => 1])
        ->and(DB::table('assay_pending_content_attaches')->count())->toBe(0)
        ->and(DB::table('assay_apps')->value('dropped_content_attach_total'))->toBe(3)
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'orphan_expired')->count())->toBe(3);
});

it('makes replay and later distinct attaches no-ops after the first accepted content', function (): void {
    $targetId = (string) UuidV7::generate();
    $first = EnvelopeFactory::attach($targetId, 'first-wins', ['instructions' => 'FIRST-CONTENT']);
    ingestAttachRecords([EnvelopeFactory::record(['record_id' => $targetId, 'invocation_id' => 'first-wins'])]);
    ingestAttachRecords([$first]);
    ingestAttachRecords([$first], receivedAt: '2026-10-01T12:02:00.000000+00:00');
    ingestAttachRecords([
        EnvelopeFactory::attach($targetId, 'first-wins', ['instructions' => 'SECOND-CONTENT']),
    ], receivedAt: '2026-10-01T12:03:00.000000+00:00');

    expect(DB::table('assay_content_attach_receipts')->count())->toBe(2)
        ->and(DB::table('assay_content_attach_receipts')->where('status', 'accepted')->count())->toBe(1)
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'target_has_content')->count())->toBe(1)
        ->and((string) DB::table('assay_record_content')->sole()->content)->toContain('FIRST-CONTENT')
        ->not->toContain('SECOND-CONTENT');
});

it('rejects cross-app cross-invocation contentful and invalid-content attaches without rolling back valid records', function (): void {
    $crossAppTarget = (string) UuidV7::generate();
    $crossInvocationTarget = (string) UuidV7::generate();
    $contentfulTarget = (string) UuidV7::generate();
    $approvalTarget = (string) UuidV7::generate();
    ingestAttachRecords([EnvelopeFactory::record(['record_id' => $crossAppTarget, 'invocation_id' => 'app-a'])], app: 'app-a');
    ingestAttachRecords([
        EnvelopeFactory::record(['record_id' => $crossInvocationTarget, 'invocation_id' => 'actual']),
        EnvelopeFactory::record(['record_id' => $contentfulTarget, 'capture' => 'full', 'invocation_id' => 'contentful', 'content' => ['instructions' => 'ORIGINAL']]),
        EnvelopeFactory::record(['record_id' => $approvalTarget, 'type' => 'tool.approval', 'invocation_id' => 'approval', 'tool_invocation_id' => 'tool-1', 'approval' => 'approved']),
    ]);

    $validRun = 'mixed-valid-run';
    ingestAttachRecords([
        EnvelopeFactory::attach($crossAppTarget, 'app-a', ['instructions' => 'CROSS-APP-CANARY']),
        EnvelopeFactory::attach($crossInvocationTarget, 'forged', ['instructions' => 'CROSS-INVOCATION-CANARY']),
        EnvelopeFactory::attach($contentfulTarget, 'contentful', ['instructions' => 'REPLACEMENT-CANARY']),
        EnvelopeFactory::attach($approvalTarget, 'approval', ['instructions' => 'INVALID-TARGET-CANARY']),
        EnvelopeFactory::record(['invocation_id' => $validRun]),
    ]);

    expect(DB::table('assay_content_attach_receipts')->where('reason', 'cross_app')->count())->toBe(1)
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'cross_invocation')->count())->toBe(1)
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'target_has_content')->count())->toBe(1)
        ->and(DB::table('assay_content_attach_receipts')->where('reason', 'invalid_content')->count())->toBe(1)
        ->and(DB::table('assay_runs')->where('invocation_id', $validRun)->exists())->toBeTrue()
        ->and(json_encode(DB::table('assay_record_content')->get(), JSON_THROW_ON_ERROR))->toContain('ORIGINAL')
        ->not->toContain('CROSS-APP-CANARY', 'CROSS-INVOCATION-CANARY', 'REPLACEMENT-CANARY', 'INVALID-TARGET-CANARY');
});

it('validates attached content against every target shape', function (array $target, array $content): void {
    $targetId = (string) UuidV7::generate();
    $invocation = 'matrix-'.str_replace('.', '-', (string) ($target['type'] ?? 'run.start')).'-'.bin2hex(random_bytes(2));
    $target['record_id'] = $targetId;
    $target['invocation_id'] = $invocation;
    ingestAttachRecords([
        EnvelopeFactory::record($target),
        EnvelopeFactory::attach($targetId, $invocation, $content),
    ]);

    expect(DB::table('assay_content_attach_receipts')->sole()->status)->toBe('accepted')
        ->and(DB::table('assay_record_content')->count())->toBe(1);
})->with([
    'agent start' => [[], ['instructions' => 'Help', 'tools' => [['name' => 'lookup', 'description' => 'Lookup', 'parameters' => ['type' => 'object']]]]],
    'step start' => function (): array {
        $message = ['role' => 'user', 'text' => 'Hello'];
        $hash = hash('sha256', json_encode($message, JSON_THROW_ON_ERROR));

        return [['type' => 'step.start', 'step' => 0], ['message_hashes' => [$hash], 'new_messages' => [$hash => $message]]];
    },
    'step end' => [['type' => 'step.end', 'step' => 0], ['output_text' => 'Done', 'structured_output' => ['ok' => true], 'tool_calls' => [['id' => 'call-1', 'name' => 'lookup', 'arguments' => []]]]],
    'tool start' => [['type' => 'tool.start', 'tool_invocation_id' => 'tool-1'], ['arguments' => ['id' => 1]]],
    'tool end' => [['type' => 'tool.end', 'tool_invocation_id' => 'tool-1', 'outcome' => 'completed'], ['result' => ['ok' => true]]],
    'failed tool end' => [['type' => 'tool.end', 'tool_invocation_id' => 'tool-2', 'outcome' => 'failed'], ['exception_message' => 'failed']],
    'failed run end' => [['type' => 'run.end', 'outcome' => 'failed'], ['exception_message' => 'failed']],
    'step fail' => [['type' => 'step.fail', 'step' => 1], ['exception_message' => 'failed']],
    'embeddings start' => [['operation' => 'embeddings', 'attempt' => null], ['inputs' => ['a']]],
    'image start' => [['operation' => 'image', 'attempt' => null], ['prompt' => 'Draw']],
    'audio start' => [['operation' => 'audio', 'attempt' => null], ['text' => 'Speak']],
    'reranking start' => [['operation' => 'reranking', 'attempt' => null], ['query' => 'q', 'documents' => ['a']]],
    'classification start' => [['operation' => 'classification', 'attempt' => null], ['prompt' => 'p', 'labels' => ['yes']]],
    'image end' => [['type' => 'run.end', 'operation' => 'image', 'attempt' => null, 'outcome' => 'completed'], ['count' => 1, 'dimensions' => [['width' => 1, 'height' => 1]]]],
    'transcription end' => [['type' => 'run.end', 'operation' => 'transcription', 'attempt' => null, 'outcome' => 'completed'], ['text' => 'Transcript']],
    'reranking end' => [['type' => 'run.end', 'operation' => 'reranking', 'attempt' => null, 'outcome' => 'completed'], ['results' => [['index' => 0, 'score' => 0.5]]]],
    'classification end' => [['type' => 'run.end', 'operation' => 'classification', 'attempt' => null, 'outcome' => 'completed'], ['answers' => ['yes' => true]]],
]);

it('registers the pending attach table as a content-bearing store', function (): void {
    expect(resolve(ContentStoreRegistry::class)->tables())->toContain(
        'assay_record_content',
        'assay_messages',
        'assay_pending_content_attaches',
    );
});
