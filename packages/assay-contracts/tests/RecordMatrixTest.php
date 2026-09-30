<?php

declare(strict_types=1);

use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Client;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\EnvelopeV1;
use ArtisanBuild\AssayContracts\FailureCapture;
use ArtisanBuild\AssayContracts\InvalidEnvelope;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\ReplayInputOmission;
use ArtisanBuild\AssayContracts\Source;
use ArtisanBuild\AssayContracts\Timestamp;
use ArtisanBuild\AssayContracts\UuidV7;

/** @param array<string, mixed> $overrides */
function matrixPayload(array $overrides = []): array
{
    return array_replace([
        'record_id' => (string) UuidV7::generate(),
        'source' => 'fake',
        'type' => RecordType::RunStart->value,
        'operation' => Operation::Agent->value,
        'invocation_id' => 'run-1',
        'attempt' => 1,
        'at' => '2026-09-30T12:34:56.123456Z',
        'capture' => CaptureMode::Usage->value,
        'sampled' => false,
    ], $overrides);
}

/** @param array<string, mixed> $payload */
function matrixRoundTrip(array $payload): RecordV1
{
    $record = RecordV1::fromArray($payload);
    $envelope = new EnvelopeV1(
        envelopeId: UuidV7::generate(),
        sentAt: new Timestamp('2026-09-30T12:34:56.123456Z'),
        client: new Client('artisan-build/assay-client', 'test'),
        sources: [new Source('fake', 'vendor/fake', 'test')],
        environment: 'testing',
        droppedTransportTotal: 0,
        droppedHookTotal: 0,
        records: [$record],
    );

    return EnvelopeCodec::decode(EnvelopeCodec::encode($envelope))->records[0];
}

it('round-trips every allowed lifecycle shape through construction encode and decode', function (array $payload): void {
    expect(matrixRoundTrip($payload)->toArray())->toMatchArray($payload);
})->with([
    'agent run start' => fn () => matrixPayload(),
    'non-agent run end' => fn () => array_diff_key(matrixPayload([
        'type' => 'run.end',
        'operation' => 'image',
        'invocation_id' => 'image-1',
        'outcome' => 'completed',
    ]), ['attempt' => true]),
    'agent failover' => fn () => matrixPayload(['type' => 'run.failover']),
    'non-agent attributed failover' => fn () => array_diff_key(matrixPayload([
        'type' => 'run.failover',
        'operation' => 'reranking',
        'invocation_id' => 'failed-invocation-a',
    ]), ['attempt' => true]),
    'unattributed failover' => fn () => [
        'record_id' => (string) UuidV7::generate(),
        'source' => 'fake',
        'type' => 'run.failover',
        'at' => '2026-09-30T12:34:56.123456Z',
        'capture' => 'usage',
        'sampled' => false,
        'model' => ['provider' => 'provider-a', 'requested' => 'model-a'],
    ],
    'step' => fn () => matrixPayload(['type' => 'step.end', 'step' => 0]),
    'tool without step' => fn () => matrixPayload([
        'type' => 'tool.end',
        'tool_invocation_id' => 'tool-1',
        'outcome' => 'completed',
    ]),
    'tool with step' => fn () => matrixPayload([
        'type' => 'tool.approval',
        'step' => 0,
        'tool_invocation_id' => 'tool-1',
        'approval' => 'requested',
    ]),
]);

it('rejects every requiredness or absence matrix violation', function (array $payload): void {
    expect(fn (): RecordV1 => RecordV1::fromArray($payload))->toThrow(InvalidEnvelope::class);
})->with([
    'run missing invocation' => fn () => matrixPayload(['invocation_id' => null]),
    'agent run missing attempt' => fn () => matrixPayload(['attempt' => null]),
    'non-agent run with attempt' => fn () => matrixPayload(['operation' => 'image']),
    'run with step' => fn () => matrixPayload(['step' => 0]),
    'run with tool invocation' => fn () => matrixPayload(['tool_invocation_id' => 'tool-1']),
    'step missing step' => fn () => matrixPayload(['type' => 'step.start']),
    'step with tool invocation' => fn () => matrixPayload(['type' => 'step.fail', 'step' => 0, 'tool_invocation_id' => 'tool-1']),
    'non-agent step' => fn () => matrixPayload(['type' => 'step.start', 'operation' => 'image', 'attempt' => null, 'step' => 0]),
    'tool missing tool invocation' => fn () => matrixPayload(['type' => 'tool.start']),
    'non-agent tool' => fn () => matrixPayload(['type' => 'tool.start', 'operation' => 'image', 'attempt' => null, 'tool_invocation_id' => 'tool-1']),
    'attributed failover missing operation' => fn () => array_diff_key(matrixPayload(['type' => 'run.failover']), ['operation' => true]),
    'non-agent failover with attempt' => fn () => matrixPayload(['type' => 'run.failover', 'operation' => 'audio']),
]);

it('accepts the exact metric set for every operation', function (Operation $operation, array $usage): void {
    $payload = matrixPayload([
        'type' => 'run.end',
        'operation' => $operation->value,
        'outcome' => 'completed',
        'usage' => $usage,
    ]);

    if ($operation !== Operation::Agent) {
        $payload['invocation_id'] = $operation->value.'-1';
        unset($payload['attempt']);
    }

    expect(matrixRoundTrip($payload)->usage?->toArray())->toBe($usage);
})->with([
    'agent text' => [Operation::Agent, ['input_tokens' => 1, 'output_tokens' => 2, 'cache_read_input_tokens' => 3, 'cache_write_input_tokens' => 4, 'reasoning_tokens' => 5]],
    'classification text' => [Operation::Classification, ['input_tokens' => 1, 'output_tokens' => 2, 'reasoning_tokens' => 3]],
    'transcription text and audio' => [Operation::Transcription, ['input_tokens' => 1, 'output_tokens' => 2, 'audio_seconds' => 1.25]],
    'image text and image' => [Operation::Image, ['input_tokens' => 1, 'image_input_tokens' => 2, 'image_output_tokens' => 3]],
    'embeddings tokens' => [Operation::Embeddings, ['input_tokens' => 1, 'output_tokens' => 2]],
    'audio tokens' => [Operation::Audio, ['input_tokens' => 1, 'output_tokens' => 2]],
    'reranking input and search' => [Operation::Reranking, ['input_tokens' => 1, 'search_units' => 2.5]],
]);

it('rejects fractional tokens in every token metric', function (string $metric): void {
    expect(fn (): RecordV1 => RecordV1::fromArray(matrixPayload([
        'type' => 'run.end',
        'outcome' => 'completed',
        'usage' => [$metric => 1.5],
    ])))->toThrow(InvalidEnvelope::class);
})->with([
    'input_tokens',
    'output_tokens',
    'cache_read_input_tokens',
    'cache_write_input_tokens',
    'reasoning_tokens',
    'image_input_tokens',
    'image_output_tokens',
]);

it('rejects usage outside step.end and run.end', function (array $fields): void {
    expect(fn (): RecordV1 => RecordV1::fromArray(matrixPayload([
        'usage' => ['input_tokens' => 1],
        ...$fields,
    ])))->toThrow(InvalidEnvelope::class);
})->with([
    'run start' => [[]],
    'failover' => [['type' => 'run.failover']],
    'step start' => [['type' => 'step.start', 'step' => 0]],
    'step fail' => [['type' => 'step.fail', 'step' => 0]],
    'tool start' => [['type' => 'tool.start', 'tool_invocation_id' => 'tool-1']],
    'tool end' => [['type' => 'tool.end', 'tool_invocation_id' => 'tool-1', 'outcome' => 'completed']],
    'tool approval' => [['type' => 'tool.approval', 'tool_invocation_id' => 'tool-1', 'approval' => 'requested']],
]);

it('rejects inapplicable metrics instead of dropping them', function (Operation $operation, string $metric): void {
    $payload = matrixPayload([
        'type' => 'run.end',
        'operation' => $operation->value,
        'outcome' => 'completed',
        'usage' => [$metric => 1],
    ]);

    if ($operation !== Operation::Agent) {
        unset($payload['attempt']);
    }

    expect(fn (): RecordV1 => RecordV1::fromArray($payload))->toThrow(InvalidEnvelope::class);
})->with([
    'reranking output' => [Operation::Reranking, 'output_tokens'],
    'transcription image' => [Operation::Transcription, 'image_input_tokens'],
    'image audio' => [Operation::Image, 'audio_seconds'],
    'embeddings reasoning' => [Operation::Embeddings, 'reasoning_tokens'],
    'audio cache' => [Operation::Audio, 'cache_read_input_tokens'],
    'agent image' => [Operation::Agent, 'image_output_tokens'],
    'classification search' => [Operation::Classification, 'search_units'],
]);

it('round-trips failure capture and replay omission enums at their exact placement', function (): void {
    $record = matrixRoundTrip(matrixPayload([
        'type' => 'run.end',
        'capture' => 'full',
        'sampled' => false,
        'outcome' => 'failed',
        'failure_capture' => FailureCapture::Truncated->value,
        'replay_inputs_omitted' => array_map(
            static fn (ReplayInputOmission $omission): string => $omission->value,
            ReplayInputOmission::cases(),
        ),
    ]));

    expect($record->failureCapture)->toBe(FailureCapture::Truncated)
        ->and($record->replayInputsOmitted)->toBe(ReplayInputOmission::cases());
});

it('rejects failure capture and replay omission outside their exact placement', function (array $fields): void {
    expect(fn (): RecordV1 => RecordV1::fromArray(matrixPayload($fields)))->toThrow(InvalidEnvelope::class);
})->with([
    'failure capture on run start' => [['failure_capture' => 'complete']],
    'failure capture sampled' => [['type' => 'run.end', 'capture' => 'full', 'sampled' => true, 'outcome' => 'failed', 'failure_capture' => 'complete']],
    'failure capture usage mode' => [['type' => 'run.end', 'outcome' => 'failed', 'failure_capture' => 'complete']],
    'failure capture completed' => [['type' => 'run.end', 'capture' => 'full', 'outcome' => 'completed', 'failure_capture' => 'complete']],
    'replay omissions on run start' => [['replay_inputs_omitted' => ['attachments']]],
    'replay omissions non-agent' => fn () => array_diff_key(matrixPayload(['type' => 'run.end', 'operation' => 'image', 'outcome' => 'completed', 'replay_inputs_omitted' => ['attachments']]), ['attempt' => true]),
    'replay omissions empty' => [['type' => 'run.end', 'outcome' => 'completed', 'replay_inputs_omitted' => []]],
    'replay omissions unknown' => [['type' => 'run.end', 'outcome' => 'completed', 'replay_inputs_omitted' => ['future_input']]],
]);

it('retains additive unknown-field tolerance around the frozen matrices', function (): void {
    $record = matrixRoundTrip(matrixPayload([
        'future_record' => true,
        'type' => 'run.end',
        'outcome' => Outcome::Completed->value,
        'usage' => ['input_tokens' => 1, 'future_metric' => 9],
    ]))->toArray();

    expect($record)->not->toHaveKey('future_record')
        ->and($record['usage'])->toBe(['input_tokens' => 1]);
});
