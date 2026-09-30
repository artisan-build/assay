<?php

declare(strict_types=1);

use App\Jobs\ProcessUsageEnvelope;
use App\Services\UsageIngestProcessor;
use Carbon\CarbonImmutable;
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

    expect($processor->reconcileStale(CarbonImmutable::parse('2026-10-01T12:30:00.000001+00:00')))->toBe(2)
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
    $envelopes = DB::table('assay_envelopes')->orderBy('received_at')->get();

    expect($agent->status)->toBe('completed')
        ->and($agent->duration_ms)->toBe('1000.000')
        ->and($operation->status)->toBe('completed')
        ->and($operation->duration_ms)->toBe('1500.000')
        ->and((string) $envelopes[0]->sent_at)->toStartWith('1900-01-01')
        ->and((string) $envelopes[0]->received_at)->toStartWith('2026-10-01');
});
