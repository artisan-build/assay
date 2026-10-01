<?php

declare(strict_types=1);

use App\Jobs\ProcessUsageEnvelope;
use App\Services\UsageIngestProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnvelopeFactory;

it('uses server receive time for operation-specific staleness and recovers on late ends', function (): void {
    config()->set('assay.stale_after_minutes', 30);
    $processor = resolve(UsageIngestProcessor::class);
    $receivedAt = '2026-10-01T12:00:00.000000+00:00';
    $starts = EnvelopeFactory::envelope([
        EnvelopeFactory::record([
            'invocation_id' => 'agent-stale',
            'at' => '2099-01-01T00:00:00.000000+00:00',
        ]),
        EnvelopeFactory::record([
            'operation' => 'embeddings',
            'invocation_id' => 'operation-stale',
            'attempt' => null,
            'at' => '1900-01-01T00:00:00.000000+00:00',
        ]),
    ], sentAt: '1900-01-01T00:00:00.000000+00:00');

    ProcessUsageEnvelope::fromContract('clock-skew-app', $receivedAt, $starts)->handle($processor);

    expect($processor->reconcileStale(CarbonImmutable::parse('2026-10-01T12:29:59.999999+00:00')))->toBe(0)
        ->and(DB::table('assay_runs')->where('invocation_id', 'agent-stale')->value('status'))->toBe('started')
        ->and(DB::table('assay_runs')->where('invocation_id', 'operation-stale')->value('status'))->toBe('started');

    expect($processor->reconcileStale(CarbonImmutable::parse('2026-10-01T12:30:00.000000+00:00')))->toBe(2)
        ->and(DB::table('assay_runs')->where('invocation_id', 'agent-stale')->value('status'))->toBe('incomplete')
        ->and(DB::table('assay_runs')->where('invocation_id', 'operation-stale')->value('status'))->toBe('failed_or_lost');
    expect($processor->reconcileStale(CarbonImmutable::parse('2026-10-01T13:00:00.000000+00:00')))->toBe(0);

    $ends = EnvelopeFactory::envelope([
        EnvelopeFactory::record([
            'type' => 'run.end',
            'invocation_id' => 'agent-stale',
            'at' => '2099-01-01T00:00:01.000000+00:00',
            'outcome' => 'completed',
            'usage' => ['input_tokens' => 1],
        ]),
        EnvelopeFactory::record([
            'type' => 'run.end',
            'operation' => 'embeddings',
            'invocation_id' => 'operation-stale',
            'attempt' => null,
            'at' => '1900-01-01T00:00:01.500000+00:00',
            'outcome' => 'completed',
            'usage' => ['input_tokens' => 1],
        ]),
    ], sentAt: '2099-01-01T00:00:00.000000+00:00');

    ProcessUsageEnvelope::fromContract(
        'clock-skew-app',
        '2026-10-01T12:31:00.000000+00:00',
        $ends,
    )->handle($processor);

    $agent = DB::table('assay_runs')->where('invocation_id', 'agent-stale')->sole();
    $operation = DB::table('assay_runs')->where('invocation_id', 'operation-stale')->sole();
    $envelopes = DB::table('assay_envelopes')->oldest('received_at')->get();

    expect($agent->status)->toBe('completed')
        ->and($agent->duration_ms)->toBe('1000.000')
        ->and($operation->status)->toBe('completed')
        ->and($operation->duration_ms)->toBe('1500.000')
        ->and((string) $envelopes[0]->sent_at)->toStartWith('1900-01-01')
        ->and((string) $envelopes[0]->received_at)->toStartWith('2026-10-01');
});

it('drains stale runs in bounded earliest-receive keyset order including non-start records and parent stubs', function (): void {
    config()->set('assay.stale_after_minutes', 30);
    config()->set('assay.stale.batch_size', 2);
    $processor = resolve(UsageIngestProcessor::class);

    ProcessUsageEnvelope::fromContract('batch-app', '2000-01-01T00:00:00.000000+00:00', EnvelopeFactory::envelope([
        EnvelopeFactory::record([
            'type' => 'step.end',
            'invocation_id' => 'child-without-start',
            'parent_invocation_id' => 'parent-stub',
            'step' => 0,
            'usage' => ['input_tokens' => 1],
        ]),
    ]))->handle($processor);
    ProcessUsageEnvelope::fromContract('batch-app', '2000-01-01T00:01:00.000000+00:00', EnvelopeFactory::envelope([
        EnvelopeFactory::record(['invocation_id' => 'agent-second']),
    ]))->handle($processor);
    ProcessUsageEnvelope::fromContract('batch-app', '2000-01-01T00:02:00.000000+00:00', EnvelopeFactory::envelope([
        EnvelopeFactory::record(['operation' => 'embeddings', 'invocation_id' => 'non-agent-third', 'attempt' => null]),
    ]))->handle($processor);
    ProcessUsageEnvelope::fromContract('batch-app', '2000-01-01T00:03:00.000000+00:00', EnvelopeFactory::envelope([
        EnvelopeFactory::record(['invocation_id' => 'agent-fourth']),
    ]))->handle($processor);
    ProcessUsageEnvelope::fromContract('batch-app', '1999-12-31T23:59:00.000000+00:00', EnvelopeFactory::envelope([
        EnvelopeFactory::record(['type' => 'tool.start', 'invocation_id' => 'child-without-start', 'step' => 1, 'tool_invocation_id' => 'tool-1']),
    ]))->handle($processor);

    $orderedIds = DB::table('assay_runs')
        ->orderBy('earliest_received_at')
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($processor->reconcileStale(CarbonImmutable::parse('2000-01-02T00:00:00.000000+00:00')))->toBe(2);

    $reconciledIds = DB::table('assay_runs')
        ->whereIn('status', ['incomplete', 'failed_or_lost'])
        ->orderBy('earliest_received_at')
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($reconciledIds)->toBe(array_slice($orderedIds, 0, 2))
        ->and(CarbonImmutable::parse((string) DB::table('assay_runs')
            ->where('invocation_id', 'child-without-start')
            ->value('earliest_received_at'))->utc()->format('Y-m-d H:i:s.u'))
        ->toBe('1999-12-31 23:59:00.000000');

    CarbonImmutable::setTestNow('2000-01-02T00:00:00.000000+00:00');

    try {
        expect(Artisan::call('assay:reconcile-stale-runs', ['--limit' => '2']))->toBe(0)
            ->and(Artisan::output())->toContain('2 stale runs reconciled.')
            ->and(Artisan::call('assay:reconcile-stale-runs', ['--limit' => '2']))->toBe(0)
            ->and(Artisan::output())->toContain('1 stale runs reconciled.')
            ->and(Artisan::call('assay:reconcile-stale-runs', ['--limit' => '2']))->toBe(0)
            ->and(Artisan::output())->toContain('0 stale runs reconciled.');
    } finally {
        CarbonImmutable::setTestNow();
    }

    expect(DB::table('assay_runs')->where('invocation_id', 'parent-stub')->value('status'))->toBe('incomplete')
        ->and(DB::table('assay_runs')->where('invocation_id', 'non-agent-third')->value('status'))->toBe('failed_or_lost');
});

it('rejects non-positive stale reconciliation limits', function (string $limit): void {
    expect(Artisan::call('assay:reconcile-stale-runs', ['--limit' => $limit]))->toBe(2)
        ->and(Artisan::output())->toContain('The --limit option must be a positive integer.');
})->with(['0', '-1', 'not-a-number']);
