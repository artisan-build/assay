<?php

declare(strict_types=1);

use App\Jobs\ProcessUsageEnvelope;
use App\Services\UsageIngestProcessor;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\EnvelopeV1;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\EnvelopeFactory;

function processEnvelope(EnvelopeV1 $envelope, string $app = 'app-one', string $receivedAt = '2026-10-01T12:05:00.000000+00:00'): void
{
    ProcessUsageEnvelope::fromContract($app, $receivedAt, $envelope)
        ->handle(resolve(UsageIngestProcessor::class));
}

it('stores every record type and operation from contract objects while assembling out of order', function (): void {
    $agent = 'agent-child';
    $parent = 'agent-parent';
    $records = [
        EnvelopeFactory::record(['type' => 'run.end', 'invocation_id' => $agent, 'parent_invocation_id' => $parent, 'parent_tool_invocation_id' => 'parent-tool', 'at' => '2026-10-01T12:00:09.000000+00:00', 'outcome' => 'completed', 'finish_reason' => 'stop', 'usage' => ['input_tokens' => 3], 'model' => ['requested' => 'asked', 'responded' => 'answered', 'provider' => 'provider-b']]),
        EnvelopeFactory::record(['type' => 'tool.approval', 'invocation_id' => $agent, 'step' => 0, 'tool_invocation_id' => 'tool-1', 'tool' => 'lookup', 'approval' => 'approved']),
        EnvelopeFactory::record(['type' => 'tool.end', 'invocation_id' => $agent, 'step' => 0, 'tool_invocation_id' => 'tool-1', 'tool' => 'lookup', 'outcome' => 'failed', 'failure_class' => LogicException::class, 'duration_ms' => 2.5]),
        EnvelopeFactory::record(['type' => 'tool.start', 'invocation_id' => $agent, 'step' => 0, 'tool_invocation_id' => 'tool-1', 'tool' => 'lookup']),
        EnvelopeFactory::record(['type' => 'step.fail', 'invocation_id' => $agent, 'step' => 1, 'failure_class' => RuntimeException::class, 'duration_ms' => 1.5]),
        EnvelopeFactory::record(['type' => 'step.end', 'invocation_id' => $agent, 'step' => 0, 'usage' => ['input_tokens' => 3], 'duration_ms' => 4.5, 'finish_reason' => 'length']),
        EnvelopeFactory::record(['type' => 'step.start', 'invocation_id' => $agent, 'step' => 0]),
        EnvelopeFactory::record(['type' => 'run.failover', 'invocation_id' => $agent, 'model' => ['provider' => 'provider-a', 'requested' => 'asked'], 'failure_class' => RuntimeException::class]),
        EnvelopeFactory::record(['type' => 'run.start', 'invocation_id' => $agent, 'parent_invocation_id' => $parent, 'parent_tool_invocation_id' => 'parent-tool', 'agent' => 'App\\Ai\\ChildAgent', 'model' => ['provider' => 'late-provider', 'requested' => 'late-requested']]),
        EnvelopeFactory::record(['type' => 'run.start', 'invocation_id' => $parent, 'agent' => 'App\\Ai\\ParentAgent']),
        EnvelopeFactory::record([
            'type' => 'run.end',
            'invocation_id' => 'metadata-run',
            'capture' => 'full',
            'sampled' => false,
            'subject' => 'subject-123',
            'outcome' => 'failed',
            'failure_class' => RuntimeException::class,
            'failure_capture' => 'truncated',
            'replay_inputs_omitted' => ['attachments', 'provider_options'],
        ]),
    ];

    $operationUsage = [
        'embeddings' => ['input_tokens' => 2, 'output_tokens' => 0],
        'image' => ['input_tokens' => 1, 'image_output_tokens' => 2],
        'audio' => ['input_tokens' => 4, 'output_tokens' => 0],
        'transcription' => ['input_tokens' => 0, 'audio_seconds' => 1.25],
        'reranking' => ['input_tokens' => 5, 'search_units' => 0.125],
        'classification' => ['input_tokens' => 6, 'reasoning_tokens' => 0],
    ];

    foreach ($operationUsage as $operation => $usage) {
        $records[] = EnvelopeFactory::record([
            'operation' => $operation,
            'invocation_id' => 'operation-'.$operation,
            'attempt' => null,
            'model' => ['provider' => 'provider-'.$operation, 'requested' => 'requested-'.$operation],
        ]);
        $records[] = EnvelopeFactory::record([
            'type' => 'run.end',
            'operation' => $operation,
            'invocation_id' => 'operation-'.$operation,
            'attempt' => null,
            'at' => '2026-10-01T12:00:01.234000+00:00',
            'outcome' => 'completed',
            'usage' => $usage,
            'model' => ['provider' => 'provider-'.$operation, 'requested' => 'requested-'.$operation],
        ]);
    }

    $records[] = EnvelopeFactory::record([
        'type' => 'run.failover',
        'operation' => null,
        'invocation_id' => null,
        'attempt' => null,
        'model' => ['provider' => 'provider-unattributed', 'requested' => 'requested-unattributed'],
        'failure_class' => RuntimeException::class,
    ]);

    processEnvelope(EnvelopeFactory::envelope($records));

    $child = DB::table('assay_runs')->where('invocation_id', $agent)->sole();
    $parentRow = DB::table('assay_runs')->where('invocation_id', $parent)->sole();
    $transcription = DB::table('assay_runs')->where('invocation_id', 'operation-transcription')->sole();
    $metadata = DB::table('assay_runs')->where('invocation_id', 'metadata-run')->sole();

    expect(DB::table('assay_records')->distinct()->pluck('type')->sort()->values()->all())->toBe([
        'run.end', 'run.failover', 'run.start', 'step.end', 'step.fail', 'step.start', 'tool.approval', 'tool.end', 'tool.start',
    ])->and(DB::table('assay_runs')->distinct()->pluck('operation')->filter()->sort()->values()->all())->toBe([
        'agent', 'audio', 'classification', 'embeddings', 'image', 'reranking', 'transcription',
    ])->and($child->parent_run_id)->toBe($parentRow->id)
        ->and($child->parent_tool_invocation_id)->toBe('parent-tool')
        ->and($child->requested_model)->toBe('asked')
        ->and($child->responded_model)->toBe('answered')
        ->and($child->provider)->toBe('provider-b')
        ->and($child->outcome)->toBe('completed')
        ->and($child->status)->toBe('completed')
        ->and($child->failure_class)->toBeNull()
        ->and($child->finish_reason)->toBe('stop')
        ->and($transcription->duration_ms)->toBe('1234.000')
        ->and($metadata->capture)->toBe('full')
        ->and($metadata->sampled)->toBeFalse()
        ->and($metadata->subject)->toBe('subject-123')
        ->and($metadata->failure_capture)->toBe('truncated')
        ->and(json_decode($metadata->replay_inputs_omitted, true, flags: JSON_THROW_ON_ERROR))->toBe(['attachments', 'provider_options'])
        ->and(DB::table('assay_steps')->count())->toBe(3)
        ->and(DB::table('assay_steps')->where('event', 'fail')->value('failure_class'))->toBe(RuntimeException::class)
        ->and(DB::table('assay_steps')->where('event', 'end')->value('finish_reason'))->toBe('length')
        ->and(DB::table('assay_tool_events')->count())->toBe(3)
        ->and(DB::table('assay_tool_events')->where('event', 'end')->value('failure_class'))->toBe(LogicException::class)
        ->and(DB::table('assay_run_failovers')->whereNotNull('run_id')->value('failure_class'))->toBe(RuntimeException::class)
        ->and(DB::table('assay_run_failovers')->whereNull('run_id')->count())->toBe(1);

    $metrics = DB::table('assay_usage_metrics')
        ->join('assay_runs', 'assay_runs.id', '=', 'assay_usage_metrics.run_id')
        ->where('assay_runs.invocation_id', 'operation-transcription')
        ->pluck('value', 'metric');

    expect($metrics->keys()->sort()->values()->all())->toBe(['audio_seconds', 'input_tokens'])
        ->and($metrics['input_tokens'])->toBe('0')
        ->and($metrics['audio_seconds'])->toBe('1.25');
    expect(DB::table('assay_usage_metrics')
        ->join('assay_runs', 'assay_runs.id', '=', 'assay_usage_metrics.run_id')
        ->where('assay_runs.invocation_id', 'operation-reranking')
        ->where('metric', 'search_units')
        ->value('value'))->toBe('0.125');
});

it('keeps run end summaries authoritative after chronological recovery and failover', function (): void {
    processEnvelope(EnvelopeFactory::envelope([
        EnvelopeFactory::record(['type' => 'run.start', 'invocation_id' => 'recovered-run']),
        EnvelopeFactory::record(['type' => 'step.start', 'invocation_id' => 'recovered-run', 'step' => 0]),
        EnvelopeFactory::record(['type' => 'step.fail', 'invocation_id' => 'recovered-run', 'step' => 0, 'failure_class' => RuntimeException::class]),
        EnvelopeFactory::record(['type' => 'step.start', 'invocation_id' => 'recovered-run', 'step' => 1]),
        EnvelopeFactory::record(['type' => 'step.end', 'invocation_id' => 'recovered-run', 'step' => 1, 'finish_reason' => 'stop', 'usage' => ['input_tokens' => 1]]),
        EnvelopeFactory::record(['type' => 'run.end', 'invocation_id' => 'recovered-run', 'outcome' => 'completed', 'finish_reason' => 'stop', 'usage' => ['input_tokens' => 1]]),
        EnvelopeFactory::record(['type' => 'run.start', 'invocation_id' => 'failover-run', 'attempt' => 1]),
        EnvelopeFactory::record(['type' => 'run.failover', 'invocation_id' => 'failover-run', 'attempt' => 1, 'model' => ['provider' => 'first', 'requested' => 'model'], 'failure_class' => LogicException::class]),
        EnvelopeFactory::record(['type' => 'run.start', 'invocation_id' => 'failover-run', 'attempt' => 2]),
        EnvelopeFactory::record(['type' => 'step.end', 'invocation_id' => 'failover-run', 'attempt' => 2, 'step' => 0, 'finish_reason' => 'stop', 'usage' => ['input_tokens' => 1]]),
        EnvelopeFactory::record(['type' => 'run.end', 'invocation_id' => 'failover-run', 'attempt' => 2, 'outcome' => 'completed', 'finish_reason' => 'stop', 'usage' => ['input_tokens' => 1]]),
    ]));

    foreach (['recovered-run', 'failover-run'] as $invocationId) {
        $run = DB::table('assay_runs')->where('invocation_id', $invocationId)->sole();

        expect($run->outcome)->toBe('completed')
            ->and($run->status)->toBe('completed')
            ->and($run->failure_class)->toBeNull()
            ->and($run->finish_reason)->toBe('stop');
    }
});

it('stores reported decimal values exactly regardless of ambient serialization precision', function (): void {
    $reportedValues = ['0.1', '0.2', '0.3', '1.1', '0.07', '812.4'];
    $records = [];
    $wireValues = [];

    foreach ($reportedValues as $index => $reportedValue) {
        $placeholder = 900_000_000 + $index;
        $records[] = EnvelopeFactory::record([
            'type' => 'run.end',
            'operation' => 'reranking',
            'invocation_id' => 'reported-decimal-'.$index,
            'attempt' => null,
            'outcome' => 'completed',
            'usage' => ['search_units' => $placeholder],
        ]);
        $wireValues['"search_units":'.$placeholder] = '"search_units":'.$reportedValue;
    }

    for ($index = 0; $index < 10; $index++) {
        $placeholder = 910_000_000 + $index;
        $records[] = EnvelopeFactory::record([
            'type' => 'run.end',
            'operation' => 'reranking',
            'invocation_id' => 'decimal-sum-'.$index,
            'attempt' => null,
            'outcome' => 'completed',
            'usage' => ['search_units' => $placeholder],
        ]);
        $wireValues['"search_units":'.$placeholder] = '"search_units":0.1';
    }

    $wire = str_replace(array_keys($wireValues), array_values($wireValues), EnvelopeCodec::encode(EnvelopeFactory::envelope($records)), $replacementCount);
    expect($replacementCount)->toBe(count($records));

    $previousPrecision = ini_set('serialize_precision', '3');

    try {
        processEnvelope(EnvelopeCodec::decode($wire));
        expect(ini_get('serialize_precision'))->toBe('3');
    } finally {
        if ($previousPrecision !== false) {
            ini_set('serialize_precision', $previousPrecision);
        }
    }

    $storedValues = DB::table('assay_usage_metrics')
        ->join('assay_runs', 'assay_runs.id', '=', 'assay_usage_metrics.run_id')
        ->whereIn('assay_runs.invocation_id', array_map(fn (int $index): string => 'reported-decimal-'.$index, array_keys($reportedValues)))
        ->orderBy('assay_runs.invocation_id')
        ->pluck('assay_usage_metrics.value')
        ->all();
    $sum = DB::table('assay_usage_metrics')
        ->join('assay_runs', 'assay_runs.id', '=', 'assay_usage_metrics.run_id')
        ->where('assay_runs.invocation_id', 'like', 'decimal-sum-%')
        ->selectRaw('SUM(assay_usage_metrics.value)::text AS total')
        ->value('total');

    expect($storedValues)->toBe($reportedValues)
        ->and($sum)->toBe('1.0');
});

it('preserves round trip decimal fidelity and checksum comparisons near the precision boundary', function (): void {
    $envelope = EnvelopeFactory::envelope([
        EnvelopeFactory::record([
            'type' => 'run.end',
            'operation' => 'reranking',
            'invocation_id' => 'precise-metric',
            'attempt' => null,
            'outcome' => 'completed',
            'usage' => ['search_units' => 0.12345678901234566],
        ]),
        EnvelopeFactory::record(['type' => 'step.end', 'invocation_id' => 'precise-checksum', 'step' => 0, 'usage' => ['input_tokens' => 1]]),
        EnvelopeFactory::record(['type' => 'run.end', 'invocation_id' => 'precise-checksum', 'outcome' => 'completed', 'usage' => ['input_tokens' => 1]]),
    ]);
    processEnvelope(EnvelopeCodec::decode(EnvelopeCodec::encode($envelope)));

    $metric = DB::table('assay_usage_metrics')
        ->join('assay_runs', 'assay_runs.id', '=', 'assay_usage_metrics.run_id')
        ->where('assay_runs.invocation_id', 'precise-metric')
        ->value('value');
    $checksumRunId = DB::table('assay_runs')->where('invocation_id', 'precise-checksum')->value('id');
    DB::table('assay_usage_metrics')->where('run_id', $checksumRunId)->where('source', 'agent_step')->update([
        'value' => '0.12345678901234566',
    ]);
    DB::table('assay_usage_metrics')->where('run_id', $checksumRunId)->where('source', 'agent_checksum')->update([
        'value' => '0.12345678901234565',
    ]);
    processEnvelope(EnvelopeFactory::envelope([
        EnvelopeFactory::record(['type' => 'tool.start', 'invocation_id' => 'precise-checksum', 'step' => 1, 'tool_invocation_id' => 'precision-tool']),
    ]));

    expect($metric)->toBe('0.12345678901234566')
        ->and((bool) DB::table('assay_runs')->where('invocation_id', 'precise-checksum')->value('checksum_mismatch'))->toBeTrue();
});

it('uses client non-agent duration only when matching start time is unavailable', function (): void {
    processEnvelope(EnvelopeFactory::envelope([
        EnvelopeFactory::record([
            'type' => 'run.end',
            'operation' => 'image',
            'invocation_id' => 'end-only-duration',
            'attempt' => null,
            'outcome' => 'completed',
            'duration_ms' => 12.345,
            'usage' => ['image_output_tokens' => 1],
        ]),
        EnvelopeFactory::record([
            'operation' => 'embeddings',
            'invocation_id' => 'derived-duration',
            'attempt' => null,
            'at' => '2026-10-01T12:00:00.000000+00:00',
        ]),
        EnvelopeFactory::record([
            'type' => 'run.end',
            'operation' => 'embeddings',
            'invocation_id' => 'derived-duration',
            'attempt' => null,
            'at' => '2026-10-01T12:00:01.500000+00:00',
            'outcome' => 'completed',
            'duration_ms' => 999.0,
            'usage' => ['input_tokens' => 1],
        ]),
    ]));

    expect(DB::table('assay_runs')->where('invocation_id', 'end-only-duration')->value('duration_ms'))->toBe('12.345')
        ->and(DB::table('assay_runs')->where('invocation_id', 'derived-duration')->value('duration_ms'))->toBe('1500.000');
});

it('keeps envelope and record replay idempotent and drop totals as independent maxima', function (): void {
    $record = EnvelopeFactory::record();
    $first = EnvelopeFactory::envelope([$record], transportDrops: 10, hookDrops: 4);

    processEnvelope($first);
    processEnvelope($first);
    processEnvelope(EnvelopeFactory::envelope([$record], transportDrops: 3, hookDrops: 8));
    processEnvelope(EnvelopeFactory::envelope([], transportDrops: 12, hookDrops: 2));

    $app = DB::table('assay_apps')->sole();
    expect(DB::table('assay_envelopes')->count())->toBe(3)
        ->and(DB::table('assay_records')->count())->toBe(1)
        ->and(DB::table('assay_runs')->count())->toBe(1)
        ->and($app->dropped_transport_total)->toBe(12)
        ->and($app->dropped_hook_total)->toBe(8);
});

it('preserves distinct repeated steps approvals and failovers by record identity', function (): void {
    $records = [];

    foreach ([1, 2] as $ordinal) {
        $records[] = EnvelopeFactory::record(['type' => 'step.start', 'step' => 0]);
        $records[] = EnvelopeFactory::record(['type' => 'tool.approval', 'step' => 0, 'tool_invocation_id' => 'tool-same', 'approval' => $ordinal === 1 ? 'requested' : 'approved']);
        $records[] = EnvelopeFactory::record(['type' => 'run.failover', 'model' => ['provider' => 'provider-a', 'requested' => 'model-a']]);
    }

    processEnvelope(EnvelopeFactory::envelope($records));

    expect(DB::table('assay_steps')->count())->toBe(2)
        ->and(DB::table('assay_tool_events')->count())->toBe(2)
        ->and(DB::table('assay_run_failovers')->count())->toBe(2);
});

it('checks only terminal attempt step sums and flags only the greater-than direction', function (): void {
    foreach (['equal' => [10, 10], 'lower' => [8, 10], 'greater' => [12, 10]] as $name => [$stepUsage, $checksum]) {
        $invocation = 'checksum-'.$name;
        processEnvelope(EnvelopeFactory::envelope([
            EnvelopeFactory::record(['type' => 'step.end', 'invocation_id' => $invocation, 'attempt' => 1, 'step' => 0, 'usage' => ['input_tokens' => 100]]),
            EnvelopeFactory::record(['type' => 'run.start', 'invocation_id' => $invocation, 'attempt' => 2, 'at' => '2026-10-01T12:00:01.000000+00:00']),
            EnvelopeFactory::record(['type' => 'step.end', 'invocation_id' => $invocation, 'attempt' => 2, 'step' => 0, 'usage' => ['input_tokens' => $stepUsage]]),
            EnvelopeFactory::record(['type' => 'run.end', 'invocation_id' => $invocation, 'attempt' => 2, 'outcome' => 'completed', 'usage' => ['input_tokens' => $checksum]]),
        ]));
    }

    expect((bool) DB::table('assay_runs')->where('invocation_id', 'checksum-equal')->value('checksum_mismatch'))->toBeFalse()
        ->and((bool) DB::table('assay_runs')->where('invocation_id', 'checksum-lower')->value('checksum_mismatch'))->toBeFalse()
        ->and((bool) DB::table('assay_runs')->where('invocation_id', 'checksum-greater')->value('checksum_mismatch'))->toBeTrue();
});

it('never persists content raw bodies or secret canaries in ingest tables or logs', function (): void {
    Log::spy();
    $canary = 'PRIVATE-CONTENT-CANARY';
    $record = EnvelopeFactory::record([
        'capture' => 'full',
        'content' => ['prompt' => $canary, 'credential' => 'SECRET-CANARY'],
        'provider_options' => ['api_key' => 'SECRET-CANARY'],
    ]);

    processEnvelope(EnvelopeFactory::envelope([$record]));

    foreach (DB::select("select tablename from pg_tables where schemaname = 'public' and tablename like 'assay_%'") as $table) {
        $rows = DB::table($table->tablename)->get();
        expect(json_encode($rows, JSON_THROW_ON_ERROR))->not->toContain($canary)->not->toContain('SECRET-CANARY');
    }

    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
        Log::shouldNotHaveReceived($level);
    }
    expect(DB::table('assay_records')->count())->toBe(1);
});
