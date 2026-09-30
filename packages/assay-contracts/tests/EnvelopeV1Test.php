<?php

declare(strict_types=1);

use ArtisanBuild\AssayContracts\Approval;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Client;
use ArtisanBuild\AssayContracts\Content;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\EnvelopeV1;
use ArtisanBuild\AssayContracts\FinishReason;
use ArtisanBuild\AssayContracts\InvalidEnvelope;
use ArtisanBuild\AssayContracts\Model;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\Source;
use ArtisanBuild\AssayContracts\Timestamp;
use ArtisanBuild\AssayContracts\Usage;
use ArtisanBuild\AssayContracts\UuidV7;
use Ramsey\Uuid\Uuid;

function validRecordPayload(array $overrides = []): array
{
    return array_replace([
        'record_id' => (string) UuidV7::generate(),
        'source' => 'laravel-ai',
        'type' => 'run.start',
        'operation' => 'agent',
        'invocation_id' => 'run-1',
        'attempt' => 1,
        'at' => '2026-09-30T12:34:56.123456Z',
        'capture' => 'usage',
        'sampled' => true,
    ], $overrides);
}

function validEnvelopePayload(array $overrides = []): array
{
    return array_replace([
        'envelope_version' => 1,
        'envelope_id' => (string) UuidV7::generate(),
        'sent_at' => '2026-09-30T12:34:56.123456+00:00',
        'client' => [
            'package' => 'artisan-build/assay-client',
            'version' => '1.2.3',
        ],
        'sources' => [[
            'driver' => 'laravel-ai',
            'package' => 'laravel/ai',
            'version' => '1.0.0',
        ]],
        'environment' => 'testing',
        'dropped_transport_total' => 0,
        'dropped_hook_total' => 0,
        'records' => [validRecordPayload()],
    ], $overrides);
}

function decodePayload(array $payload): EnvelopeV1
{
    return EnvelopeCodec::decode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
}

it('round-trips a canonical envelope and usage-bearing record', function (): void {
    $usage = new Usage(
        inputTokens: 10,
        outputTokens: 11,
        cacheReadInputTokens: 12,
        cacheWriteInputTokens: 13,
        reasoningTokens: 14,
    );
    $recordId = UuidV7::generate();
    $envelopeId = UuidV7::generate();
    $envelope = new EnvelopeV1(
        envelopeId: $envelopeId,
        sentAt: new Timestamp('2026-09-30T12:34:56.123456Z'),
        client: new Client('artisan-build/assay-client', '1.2.3'),
        sources: [new Source('laravel-ai', 'laravel/ai', '1.0.0')],
        environment: 'production',
        droppedTransportTotal: 3,
        droppedHookTotal: 4,
        records: [new RecordV1(
            recordId: $recordId,
            source: 'laravel-ai',
            type: RecordType::StepEnd,
            operation: Operation::Agent,
            at: new Timestamp('2026-09-30T12:34:57.654321-05:00'),
            capture: CaptureMode::Full,
            sampled: false,
            invocationId: 'invocation-1',
            attempt: 2,
            parentInvocationId: 'parent-invocation-1',
            parentToolInvocationId: 'parent-tool-1',
            step: 0,
            subject: 'user:123',
            usage: $usage,
            model: new Model(requested: 'requested-model', responded: 'responded-model', provider: 'provider-name'),
            content: new Content(['messages' => [['role' => 'user', 'body' => 'test-created body']]]),
            agent: 'App\\Ai\\SupportAgent',
            durationMs: 812.4,
            finishReason: FinishReason::Stop,
        )],
        deploy: 'release-123',
    );

    $json = EnvelopeCodec::encode($envelope);
    $decoded = EnvelopeCodec::decode($json);
    $wire = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    expect(EnvelopeCodec::encode($decoded))->toBe($json)
        ->and($wire['envelope_version'])->toBe(1)
        ->and($wire['envelope_id'])->toBe((string) $envelopeId)
        ->and($wire['deploy'])->toBe('release-123')
        ->and($wire['records'][0]['record_id'])->toBe((string) $recordId)
        ->and($wire['records'][0]['usage'])->toBe($usage->toArray())
        ->and($wire['records'][0]['duration_ms'])->toBe(812.4)
        ->and($wire['records'][0]['content']['messages'][0]['body'])->toBe('test-created body')
        ->and($wire)->not->toHaveKey('app_id');
});

it('round-trips every record type', function (RecordType $type): void {
    $metadata = match ($type) {
        RecordType::RunEnd => ['outcome' => Outcome::Completed->value],
        RecordType::StepStart, RecordType::StepEnd, RecordType::StepFail => ['step' => 0],
        RecordType::ToolStart => ['tool_invocation_id' => 'tool-1'],
        RecordType::ToolEnd => ['tool_invocation_id' => 'tool-1', 'outcome' => Outcome::Completed->value],
        RecordType::ToolApproval => ['tool_invocation_id' => 'tool-1', 'approval' => Approval::Requested->value],
        default => [],
    };
    $decoded = decodePayload(validEnvelopePayload([
        'records' => [validRecordPayload(['type' => $type->value, ...$metadata])],
    ]));

    expect($decoded->records[0]->type)->toBe($type)
        ->and($decoded->records[0]->operation)->toBe(Operation::Agent)
        ->and($decoded->records[0]->toArray()['operation'])->toBe(Operation::Agent->value);
})->with(RecordType::cases());

it('round-trips every operation', function (Operation $operation): void {
    $record = validRecordPayload([
        'operation' => $operation->value,
    ]);

    if ($operation !== Operation::Agent) {
        unset($record['attempt']);
    }

    $decoded = decodePayload(validEnvelopePayload([
        'records' => [$record],
    ]));

    expect($decoded->records[0]->operation)->toBe($operation);
})->with(Operation::cases());

it('round-trips every capture mode', function (CaptureMode $capture): void {
    $decoded = decodePayload(validEnvelopePayload([
        'records' => [validRecordPayload(['capture' => $capture->value])],
    ]));

    expect($decoded->records[0]->capture)->toBe($capture);
})->with(CaptureMode::cases());

it('keeps omitted metrics and optional fields absent', function (): void {
    $payload = validEnvelopePayload([
        'records' => [validRecordPayload([
            'type' => 'run.end',
            'outcome' => 'completed',
            'usage' => ['output_tokens' => 0],
        ])],
    ]);

    $wire = json_decode(EnvelopeCodec::encode(decodePayload($payload)), true, 512, JSON_THROW_ON_ERROR);

    expect($wire)->not->toHaveKey('deploy')
        ->and($wire['records'][0])->not->toHaveKeys([
            'parent_invocation_id',
            'parent_tool_invocation_id',
            'step',
            'tool_invocation_id',
            'subject',
            'model',
            'agent',
            'tool',
            'duration_ms',
            'finish_reason',
            'approval',
            'failure_class',
            'failure_capture',
            'replay_inputs_omitted',
            'content',
        ])
        ->and($wire['records'][0]['usage'])->toBe(['output_tokens' => 0])
        ->and($wire['records'][0]['usage'])->not->toHaveKey('input_tokens');
});

it('ignores additive fields at every defined object layer', function (): void {
    $payload = validEnvelopePayload([
        'future_envelope' => true,
        'client' => [
            'package' => 'artisan-build/assay-client',
            'version' => '1.2.3',
            'future_client' => true,
        ],
        'sources' => [[
            'driver' => 'laravel-ai',
            'package' => 'laravel/ai',
            'version' => '1.0.0',
            'future_source' => true,
        ]],
        'records' => [validRecordPayload([
            'future_record' => true,
            'type' => 'run.end',
            'outcome' => 'completed',
            'usage' => ['input_tokens' => 7, 'future_usage' => 8],
            'model' => ['requested' => 'model-a', 'future_model' => true],
        ])],
    ]);

    $wire = json_decode(EnvelopeCodec::encode(decodePayload($payload)), true, 512, JSON_THROW_ON_ERROR);

    expect($wire)->not->toHaveKeys(['future_envelope'])
        ->and($wire['client'])->not->toHaveKey('future_client')
        ->and($wire['sources'][0])->not->toHaveKey('future_source')
        ->and($wire['records'][0])->not->toHaveKey('future_record')
        ->and($wire['records'][0]['usage'])->toBe(['input_tokens' => 7])
        ->and($wire['records'][0]['model'])->toBe(['requested' => 'model-a']);
});

it('preserves product-defined content object and list shapes', function (): void {
    $json = json_encode(validEnvelopePayload([
        'records' => [validRecordPayload(['capture' => 'full'])],
    ]), JSON_THROW_ON_ERROR);
    $payload = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    $payload->records[0]->content = (object) [
        'empty_object' => (object) [],
        'empty_list' => [],
        'nested' => (object) ['body' => 'test-created content'],
    ];
    $contentJson = json_encode($payload->records[0]->content, JSON_THROW_ON_ERROR);

    $roundTripped = json_decode(EnvelopeCodec::encode(EnvelopeCodec::decode(json_encode($payload, JSON_THROW_ON_ERROR))), false, 512, JSON_THROW_ON_ERROR);

    expect(json_encode($roundTripped->records[0]->content, JSON_THROW_ON_ERROR))->toBe($contentJson);
});

it('encodes and decodes constructed empty content as an object', function (): void {
    $envelope = new EnvelopeV1(
        envelopeId: UuidV7::generate(),
        sentAt: new Timestamp('2026-09-30T12:34:56.123456Z'),
        client: new Client('artisan-build/assay-client', '1.2.3'),
        sources: [new Source('laravel-ai', 'laravel/ai', '1.0.0')],
        environment: 'testing',
        droppedTransportTotal: 0,
        droppedHookTotal: 0,
        records: [new RecordV1(
            recordId: UuidV7::generate(),
            source: 'laravel-ai',
            type: RecordType::RunEnd,
            operation: Operation::Agent,
            at: new Timestamp('2026-09-30T12:34:57.123456Z'),
            capture: CaptureMode::Full,
            sampled: true,
            invocationId: 'run-1',
            attempt: 1,
            content: new Content([]),
            outcome: Outcome::Completed,
        )],
    );

    $json = EnvelopeCodec::encode($envelope);
    $wire = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    $roundTripped = json_decode(EnvelopeCodec::encode(EnvelopeCodec::decode($json)), false, 512, JSON_THROW_ON_ERROR);

    expect($wire->records[0]->content)->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($wire->records[0]->content))->toBe([])
        ->and($roundTripped->records[0]->content)->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($roundTripped->records[0]->content))->toBe([]);
});

it('rejects constructed empty usage and model objects', function (): void {
    expect(fn (): Usage => new Usage)->toThrow(InvalidEnvelope::class)
        ->and(fn (): Model => new Model)->toThrow(InvalidEnvelope::class);
});

it('rejects decoded empty usage and model objects', function (string $field): void {
    $payload = json_decode(json_encode(validEnvelopePayload(), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $payload->records[0]->{$field} = new stdClass;

    expect(fn (): EnvelopeV1 => EnvelopeCodec::decode(json_encode($payload, JSON_THROW_ON_ERROR)))
        ->toThrow(InvalidEnvelope::class);
})->with(['usage', 'model']);

it('generates and validates UUIDv7 values', function (): void {
    $generated = UuidV7::generate();

    expect(Uuid::fromString((string) $generated)->getFields()->getVersion())->toBe(7)
        ->and(fn (): UuidV7 => new UuidV7(Uuid::uuid4()->toString()))->toThrow(InvalidEnvelope::class);
});

it('rejects unsupported versions and invalid scalar invariants', function (Closure $mutate): void {
    $payload = validEnvelopePayload();
    $mutate($payload);

    expect(fn (): EnvelopeV1 => decodePayload($payload))->toThrow(InvalidEnvelope::class);
})->with([
    'unsupported version' => fn (array &$payload) => $payload['envelope_version'] = 2,
    'version string' => fn (array &$payload) => $payload['envelope_version'] = '1',
    'uuid v4 envelope id' => fn (array &$payload) => $payload['envelope_id'] = Uuid::uuid4()->toString(),
    'uuid v4 record id' => fn (array &$payload) => $payload['records'][0]['record_id'] = Uuid::uuid4()->toString(),
    'timestamp without microseconds' => fn (array &$payload) => $payload['sent_at'] = '2026-09-30T12:34:56Z',
    'invalid calendar date' => fn (array &$payload) => $payload['records'][0]['at'] = '2026-02-30T12:34:56.123456Z',
    'invalid hour' => fn (array &$payload) => $payload['sent_at'] = '2026-09-30T25:34:56.123456Z',
    'invalid timezone offset' => fn (array &$payload) => $payload['sent_at'] = '2026-09-30T12:34:56.123456+99:00',
    'negative transport drops' => fn (array &$payload) => $payload['dropped_transport_total'] = -1,
    'negative hook drops' => fn (array &$payload) => $payload['dropped_hook_total'] = -1,
    'zero attempt' => fn (array &$payload) => $payload['records'][0]['attempt'] = 0,
    'negative step' => fn (array &$payload) => $payload['records'][0]['step'] = -1,
    'invalid type' => fn (array &$payload) => $payload['records'][0]['type'] = 'run.future',
    'invalid operation' => fn (array &$payload) => $payload['records'][0]['operation'] = 'chat',
    'invalid capture' => fn (array &$payload) => $payload['records'][0]['capture'] = 'none',
    'content without full capture' => fn (array &$payload) => $payload['records'][0]['content'] = ['body' => 'not full'],
    'sampled integer' => fn (array &$payload) => $payload['records'][0]['sampled'] = 1,
    'negative usage' => fn (array &$payload) => $payload['records'][0]['usage'] = ['input_tokens' => -1],
    'string usage' => fn (array &$payload) => $payload['records'][0]['usage'] = ['audio_seconds' => '1.5'],
]);

it('rejects malformed object and list shapes', function (Closure $mutate): void {
    $payload = validEnvelopePayload();
    $mutate($payload);

    expect(fn (): EnvelopeV1 => decodePayload($payload))->toThrow(InvalidEnvelope::class);
})->with([
    'client list' => fn (array &$payload) => $payload['client'] = ['not', 'an', 'object'],
    'sources object' => fn (array &$payload) => $payload['sources'] = ['driver' => 'laravel-ai'],
    'source scalar' => fn (array &$payload) => $payload['sources'] = ['laravel-ai'],
    'records object' => fn (array &$payload) => $payload['records'] = ['record_id' => 'invalid'],
    'record scalar' => fn (array &$payload) => $payload['records'] = ['record'],
    'usage list' => fn (array &$payload) => $payload['records'][0]['usage'] = [1, 2],
    'empty usage list' => fn (array &$payload) => $payload['records'][0]['usage'] = [],
    'model list' => fn (array &$payload) => $payload['records'][0]['model'] = ['a', 'b'],
    'empty model list' => fn (array &$payload) => $payload['records'][0]['model'] = [],
    'content list' => fn (array &$payload) => $payload['records'][0]['content'] = ['a', 'b'],
    'empty content list' => fn (array &$payload) => $payload['records'][0]['content'] = [],
    'missing records' => function (array &$payload): void {
        unset($payload['records']);
    },
]);

it('rejects non-finite values before encoding', function (): void {
    expect(fn (): Usage => new Usage(audioSeconds: INF))->toThrow(InvalidEnvelope::class)
        ->and(fn (): Content => new Content(['score' => NAN]))->toThrow(InvalidEnvelope::class);
});

it('rejects malformed JSON', function (): void {
    expect(fn (): EnvelopeV1 => EnvelopeCodec::decode('{'))->toThrow(InvalidEnvelope::class);
});
