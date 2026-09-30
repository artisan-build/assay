<?php

declare(strict_types=1);

use ArtisanBuild\AssayContracts\Approval;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\FinishReason;
use ArtisanBuild\AssayContracts\InvalidEnvelope;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\Timestamp;
use ArtisanBuild\AssayContracts\UuidV7;

/** @param array<string, mixed> $overrides */
function metadataPayload(array $overrides = []): array
{
    return array_replace([
        'record_id' => (string) UuidV7::generate(),
        'source' => 'fake',
        'type' => RecordType::RunStart->value,
        'operation' => Operation::Agent->value,
        'at' => '2026-09-30T12:34:56.123456Z',
        'capture' => CaptureMode::Usage->value,
        'sampled' => false,
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function metadataRecord(array $overrides = []): RecordV1
{
    return new RecordV1(...array_replace([
        'recordId' => UuidV7::generate(),
        'source' => 'fake',
        'type' => RecordType::RunStart,
        'operation' => Operation::Agent,
        'at' => new Timestamp('2026-09-30T12:34:56.123456Z'),
        'capture' => CaptureMode::Usage,
        'sampled' => false,
    ], $overrides));
}

it('round-trips every finish reason', function (FinishReason $finishReason): void {
    $record = RecordV1::fromArray(metadataPayload([
        'type' => RecordType::StepEnd->value,
        'finish_reason' => $finishReason->value,
    ]));

    expect($record->finishReason)->toBe($finishReason)
        ->and($record->toArray()['finish_reason'])->toBe($finishReason->value);
})->with(FinishReason::cases());

it('round-trips every outcome', function (Outcome $outcome): void {
    $record = RecordV1::fromArray(metadataPayload([
        'type' => RecordType::RunEnd->value,
        'outcome' => $outcome->value,
    ]));

    expect($record->outcome)->toBe($outcome)
        ->and($record->toArray()['outcome'])->toBe($outcome->value);
})->with(Outcome::cases());

it('round-trips every approval', function (Approval $approval): void {
    $record = RecordV1::fromArray(metadataPayload([
        'type' => RecordType::ToolApproval->value,
        'approval' => $approval->value,
    ]));

    expect($record->approval)->toBe($approval)
        ->and($record->toArray()['approval'])->toBe($approval->value);
})->with(Approval::cases());

it('keeps duration_ms strictly floating point', function (): void {
    $record = RecordV1::fromArray(metadataPayload([
        'type' => RecordType::StepEnd->value,
        'duration_ms' => 0.0,
    ]));

    expect($record->durationMs)->toBeFloat()->toBe(0.0)
        ->and($record->toArray()['duration_ms'])->toBeFloat()->toBe(0.0);
});

it('accepts every allowed metadata placement', function (array $metadata): void {
    expect(RecordV1::fromArray(metadataPayload($metadata)))->toBeInstanceOf(RecordV1::class);
})->with([
    'agent run' => [['agent' => 'App\\Ai\\Agent']],
    'agent step' => [['type' => 'step.start', 'agent' => 'App\\Ai\\Agent']],
    'agent tool' => [['type' => 'tool.start', 'agent' => 'App\\Ai\\Agent']],
    'tool start' => [['type' => 'tool.start', 'tool' => 'lookup_order']],
    'tool end' => [['type' => 'tool.end', 'tool' => 'lookup_order', 'outcome' => 'completed']],
    'tool approval' => [['type' => 'tool.approval', 'tool' => 'lookup_order', 'approval' => 'requested']],
    'step end duration' => [['type' => 'step.end', 'duration_ms' => 0.0]],
    'step fail duration' => [['type' => 'step.fail', 'duration_ms' => 1.5]],
    'tool end duration' => [['type' => 'tool.end', 'duration_ms' => 1.5, 'outcome' => 'completed']],
    'non-agent run duration' => [['type' => 'run.end', 'operation' => 'image', 'duration_ms' => 1.5, 'outcome' => 'completed']],
    'step finish' => [['type' => 'step.end', 'finish_reason' => 'stop']],
    'run finish' => [['type' => 'run.end', 'finish_reason' => 'length', 'outcome' => 'completed']],
    'failed run class' => [['type' => 'run.end', 'outcome' => 'failed', 'failure_class' => 'App\\Exceptions\\Failure']],
    'step failure class' => [['type' => 'step.fail', 'failure_class' => 'App\\Exceptions\\Failure']],
    'failed tool class' => [['type' => 'tool.end', 'outcome' => 'failed', 'failure_class' => 'App\\Exceptions\\Failure']],
    'failover class' => [['type' => 'run.failover', 'failure_class' => 'App\\Exceptions\\Failure']],
]);

it('rejects forbidden metadata placements when decoding', function (array $metadata): void {
    expect(fn (): RecordV1 => RecordV1::fromArray(metadataPayload($metadata)))
        ->toThrow(InvalidEnvelope::class);
})->with([
    'agent on non-agent operation' => [['operation' => 'image', 'agent' => 'App\\Ai\\Agent']],
    'tool on run' => [['tool' => 'lookup_order']],
    'duration on step start' => [['type' => 'step.start', 'duration_ms' => 1.0]],
    'duration on agent run end' => [['type' => 'run.end', 'duration_ms' => 1.0, 'outcome' => 'completed']],
    'finish on step fail' => [['type' => 'step.fail', 'finish_reason' => 'error']],
    'outcome on run start' => [['outcome' => 'completed']],
    'approval on tool start' => [['type' => 'tool.start', 'approval' => 'requested']],
    'failure on completed run' => [['type' => 'run.end', 'outcome' => 'completed', 'failure_class' => 'RuntimeException']],
    'failure on completed tool' => [['type' => 'tool.end', 'outcome' => 'completed', 'failure_class' => 'RuntimeException']],
    'failure on run start' => [['failure_class' => 'RuntimeException']],
]);

it('requires outcome and approval on their lifecycle records', function (string $type): void {
    expect(fn (): RecordV1 => RecordV1::fromArray(metadataPayload(['type' => $type])))
        ->toThrow(InvalidEnvelope::class);
})->with([
    RecordType::RunEnd->value,
    RecordType::ToolEnd->value,
    RecordType::ToolApproval->value,
]);

it('rejects unknown and malformed enum fields', function (array $metadata): void {
    expect(fn (): RecordV1 => RecordV1::fromArray(metadataPayload($metadata)))
        ->toThrow(InvalidEnvelope::class);
})->with([
    'unknown finish reason' => [['type' => 'step.end', 'finish_reason' => 'done']],
    'unknown outcome' => [['type' => 'run.end', 'outcome' => 'partial']],
    'unknown approval' => [['type' => 'tool.approval', 'approval' => 'pending']],
    'null finish reason' => [['type' => 'step.end', 'finish_reason' => null]],
    'integer outcome' => [['type' => 'run.end', 'outcome' => 1]],
]);

it('enforces metadata string character boundaries', function (): void {
    $unicodeBoundary = str_repeat('é', 255);

    expect(RecordV1::fromArray(metadataPayload(['agent' => $unicodeBoundary]))->agent)->toBe($unicodeBoundary)
        ->and(fn (): RecordV1 => RecordV1::fromArray(metadataPayload(['agent' => ''])))->toThrow(InvalidEnvelope::class)
        ->and(fn (): RecordV1 => RecordV1::fromArray(metadataPayload(['agent' => str_repeat('a', 256)])))->toThrow(InvalidEnvelope::class)
        ->and(fn (): RecordV1 => RecordV1::fromArray(metadataPayload(['agent' => "App\\Ai\nAgent"])))->toThrow(InvalidEnvelope::class)
        ->and(fn (): RecordV1 => RecordV1::fromArray(metadataPayload(['type' => 'tool.start', 'tool' => "lookup\0order"])))->toThrow(InvalidEnvelope::class);
});

it('enforces failure class grammar and boundaries', function (string $failureClass, bool $valid): void {
    $decode = fn (): RecordV1 => RecordV1::fromArray(metadataPayload([
        'type' => 'step.fail',
        'failure_class' => $failureClass,
    ]));

    if ($valid) {
        expect($decode()->failureClass)->toBe($failureClass);

        return;
    }

    expect($decode)->toThrow(InvalidEnvelope::class);
})->with([
    'fqcn' => ['App\\Exceptions\\Failure_2', true],
    'leading separator' => ['\\App\\Exceptions\\Failure', true],
    'message text' => ['App\\Exceptions\\Failure: secret message', false],
    'hyphen' => ['App\\Bad-Failure', false],
    'too long' => [str_repeat('A', 256), false],
]);

it('rejects malformed and unsafe durations', function (mixed $duration): void {
    expect(fn (): RecordV1 => RecordV1::fromArray(metadataPayload([
        'type' => 'step.end',
        'duration_ms' => $duration,
    ])))->toThrow(InvalidEnvelope::class);
})->with([
    'integer' => 1,
    'string' => '1.0',
    'null' => null,
    'negative' => -0.1,
]);

it('validates constructed metadata as strictly as decoded metadata', function (): void {
    expect(fn (): RecordV1 => metadataRecord([
        'type' => RecordType::RunEnd,
        'outcome' => null,
    ]))->toThrow(InvalidEnvelope::class)
        ->and(fn (): RecordV1 => metadataRecord([
            'operation' => Operation::Image,
            'agent' => 'App\\Ai\\Agent',
        ]))->toThrow(InvalidEnvelope::class)
        ->and(fn (): RecordV1 => metadataRecord([
            'type' => RecordType::ToolStart,
            'tool' => "bad\ntool",
        ]))->toThrow(InvalidEnvelope::class)
        ->and(fn (): RecordV1 => metadataRecord([
            'type' => RecordType::StepFail,
            'failureClass' => 'exception message text',
        ]))->toThrow(InvalidEnvelope::class)
        ->and(fn (): RecordV1 => metadataRecord([
            'type' => RecordType::StepEnd,
            'durationMs' => 1,
        ]))->toThrow(InvalidEnvelope::class)
        ->and(fn (): RecordV1 => metadataRecord([
            'type' => RecordType::StepEnd,
            'durationMs' => INF,
        ]))->toThrow(InvalidEnvelope::class)
        ->and(fn (): RecordV1 => metadataRecord([
            'type' => RecordType::StepEnd,
            'durationMs' => NAN,
        ]))->toThrow(InvalidEnvelope::class);
});
