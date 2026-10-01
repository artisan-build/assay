<?php

declare(strict_types=1);

use ArtisanBuild\AssayContracts\Approval;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Content;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\FailureCapture;
use ArtisanBuild\AssayContracts\FinishReason;
use ArtisanBuild\AssayContracts\InvalidEnvelope;
use ArtisanBuild\AssayContracts\Model;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\ReplayInputOmission;
use ArtisanBuild\AssayContracts\Timestamp;
use ArtisanBuild\AssayContracts\Usage;
use ArtisanBuild\AssayContracts\UuidV7;

function attachPayload(array $overrides = []): array
{
    return array_replace([
        'record_id' => (string) UuidV7::generate(),
        'type' => RecordType::ContentAttach->value,
        'target_record_id' => (string) UuidV7::generate(),
        'invocation_id' => 'run-1',
        'at' => '2026-10-01T12:34:56.123456Z',
        'capture' => CaptureMode::Full->value,
        'content' => [
            'structured_output' => [
                'empty_object' => (object) [],
                'empty_list' => [],
            ],
        ],
    ], $overrides);
}

function attachConstruction(array $overrides = []): RecordV1
{
    return new RecordV1(...array_replace([
        'recordId' => UuidV7::generate(),
        'source' => null,
        'type' => RecordType::ContentAttach,
        'operation' => null,
        'at' => new Timestamp('2026-10-01T12:34:56.123456Z'),
        'capture' => CaptureMode::Full,
        'sampled' => null,
        'invocationId' => 'run-1',
        'content' => new Content(['output_text' => 'post-hook']),
        'targetRecordId' => UuidV7::generate(),
    ], $overrides));
}

it('round-trips only the exact content attach shape while preserving JSON identities', function (): void {
    $record = matrixRoundTrip(attachPayload());
    $actual = $record->toArray();
    unset($actual['content']);

    $expected = attachPayload([
        'record_id' => (string) $record->recordId,
        'target_record_id' => (string) $record->targetRecordId,
    ]);
    unset($expected['content']);

    expect($actual)->toBe($expected)
        ->and($record->content?->toArray()['structured_output']['empty_object'])->toBeInstanceOf(stdClass::class)
        ->and($record->content?->toArray()['structured_output']['empty_list'])->toBe([]);
});

it('rejects every forbidden content attach field during construction', function (array $override): void {
    expect(fn (): RecordV1 => attachConstruction($override))->toThrow(InvalidEnvelope::class);
})->with([
    'source' => [['source' => 'fake']],
    'operation' => [['operation' => Operation::Agent]],
    'sampled' => [['sampled' => false]],
    'attempt' => [['attempt' => 1]],
    'parent invocation' => [['parentInvocationId' => 'parent']],
    'parent tool' => [['parentToolInvocationId' => 'tool-parent']],
    'step' => [['step' => 0]],
    'tool invocation' => [['toolInvocationId' => 'tool-1']],
    'subject' => [['subject' => 'user:1']],
    'agent' => [['agent' => 'App\\Agent']],
    'tool' => [['tool' => 'lookup']],
    'duration' => [['durationMs' => 1.0]],
    'usage' => [['usage' => new Usage(inputTokens: 1)]],
    'model' => [['model' => new Model(requested: 'model')]],
    'finish reason' => [['finishReason' => FinishReason::Stop]],
    'outcome' => [['outcome' => Outcome::Failed]],
    'approval' => [['approval' => Approval::Approved]],
    'failure class' => [['failureClass' => RuntimeException::class]],
    'failure capture' => [['failureCapture' => FailureCapture::Complete]],
    'replay inputs' => [['replayInputsOmitted' => [ReplayInputOmission::Attachments]]],
]);

it('rejects missing invalid and smuggled content attach wire fields', function (array $payload): void {
    expect(fn (): RecordV1 => RecordV1::fromArray($payload))->toThrow(InvalidEnvelope::class);

    $json = json_encode([
        'envelope_version' => 1,
        'envelope_id' => (string) UuidV7::generate(),
        'sent_at' => '2026-10-01T12:34:56.123456Z',
        'client' => ['package' => 'artisan-build/assay-client', 'version' => 'test'],
        'sources' => [['driver' => 'fake', 'package' => 'vendor/fake', 'version' => 'test']],
        'environment' => 'testing',
        'dropped_transport_total' => 0,
        'dropped_hook_total' => 0,
        'records' => [$payload],
    ], JSON_THROW_ON_ERROR);

    expect(fn () => EnvelopeCodec::decode($json))->toThrow(InvalidEnvelope::class);
})->with([
    'missing target' => fn () => array_diff_key(attachPayload(), ['target_record_id' => true]),
    'missing invocation' => fn () => array_diff_key(attachPayload(), ['invocation_id' => true]),
    'missing content' => fn () => array_diff_key(attachPayload(), ['content' => true]),
    'invalid record uuid' => fn () => attachPayload(['record_id' => 'not-a-uuid']),
    'invalid target uuid' => fn () => attachPayload(['target_record_id' => 'not-a-uuid']),
    'empty invocation' => fn () => attachPayload(['invocation_id' => '']),
    'usage capture' => fn () => attachPayload(['capture' => 'usage']),
    'content list' => fn () => attachPayload(['content' => ['item']]),
    'content scalar' => fn () => attachPayload(['content' => 'secret']),
    'source smuggling' => fn () => attachPayload(['source' => 'fake']),
    'operation smuggling' => fn () => attachPayload(['operation' => 'agent']),
    'sampled smuggling' => fn () => attachPayload(['sampled' => false]),
    'usage smuggling' => fn () => attachPayload(['usage' => ['input_tokens' => 1]]),
    'model smuggling' => fn () => attachPayload(['model' => ['requested' => 'model']]),
    'attempt smuggling' => fn () => attachPayload(['attempt' => 1]),
    'parent invocation smuggling' => fn () => attachPayload(['parent_invocation_id' => 'parent']),
    'parent tool smuggling' => fn () => attachPayload(['parent_tool_invocation_id' => 'parent-tool']),
    'step smuggling' => fn () => attachPayload(['step' => 0]),
    'tool invocation smuggling' => fn () => attachPayload(['tool_invocation_id' => 'tool-1']),
    'subject smuggling' => fn () => attachPayload(['subject' => 'user:1']),
    'agent smuggling' => fn () => attachPayload(['agent' => 'App\\Agent']),
    'tool smuggling' => fn () => attachPayload(['tool' => 'lookup']),
    'duration smuggling' => fn () => attachPayload(['duration_ms' => 1.0]),
    'finish reason smuggling' => fn () => attachPayload(['finish_reason' => 'stop']),
    'metadata smuggling' => fn () => attachPayload(['outcome' => 'failed']),
    'approval smuggling' => fn () => attachPayload(['approval' => 'approved']),
    'failure class smuggling' => fn () => attachPayload(['failure_class' => RuntimeException::class]),
    'failure capture smuggling' => fn () => attachPayload(['failure_capture' => 'complete']),
    'replay metadata smuggling' => fn () => attachPayload(['replay_inputs_omitted' => ['attachments']]),
    'unknown smuggling' => fn () => attachPayload(['future_record' => true]),
]);

it('keeps strict decode while allowing ingest to isolate only otherwise-valid metadata smuggling', function (): void {
    $attach = attachPayload(['source' => 'forbidden']);
    $normal = [
        'record_id' => (string) UuidV7::generate(),
        'source' => 'fake',
        'type' => 'run.start',
        'operation' => 'agent',
        'invocation_id' => 'run-2',
        'attempt' => 1,
        'at' => '2026-10-01T12:34:56.123456Z',
        'capture' => 'usage',
        'sampled' => false,
    ];
    $json = json_encode([
        'envelope_version' => 1,
        'envelope_id' => (string) UuidV7::generate(),
        'sent_at' => '2026-10-01T12:34:56.123456Z',
        'client' => ['package' => 'artisan-build/assay-client', 'version' => 'test'],
        'sources' => [['driver' => 'fake', 'package' => 'vendor/fake', 'version' => 'test']],
        'environment' => 'testing',
        'dropped_transport_total' => 0,
        'dropped_hook_total' => 0,
        'records' => [$normal, $attach],
    ], JSON_THROW_ON_ERROR);
    $rejected = [];

    expect(fn () => EnvelopeCodec::decode($json))->toThrow(InvalidEnvelope::class);

    $envelope = EnvelopeCodec::decodeForIngest(
        $json,
        static function (int $index, string $recordId) use (&$rejected): void {
            $rejected[] = [$index, $recordId];
        },
    );

    expect($envelope->records)->toHaveCount(1)
        ->and((string) $envelope->records[0]->recordId)->toBe($normal['record_id'])
        ->and($rejected)->toBe([[1, $attach['record_id']]]);
});

it('allows standalone attach content that only the target can validate', function (): void {
    $record = RecordV1::fromArray(attachPayload(['content' => ['target_specific_key' => ['nested' => true]]]));

    expect($record->content?->toArray())->toBe(['target_specific_key' => ['nested' => true]]);
});
