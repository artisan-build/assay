<?php

declare(strict_types=1);

use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\InvalidEnvelope;
use ArtisanBuild\AssayContracts\Model;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\Timestamp;
use ArtisanBuild\AssayContracts\UuidV7;

/** @param array<string, mixed> $overrides */
function unattributedFailoverPayload(array $overrides = []): array
{
    return array_replace([
        'record_id' => (string) UuidV7::generate(),
        'source' => 'fake',
        'type' => RecordType::RunFailover->value,
        'at' => '2026-09-30T12:34:56.123456Z',
        'capture' => CaptureMode::Usage->value,
        'sampled' => false,
        'model' => [
            'provider' => 'provider-name',
            'requested' => 'requested-model',
        ],
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function unattributedFailoverRecord(array $overrides = []): RecordV1
{
    return new RecordV1(...array_replace([
        'recordId' => UuidV7::generate(),
        'source' => 'fake',
        'type' => RecordType::RunFailover,
        'operation' => null,
        'at' => new Timestamp('2026-09-30T12:34:56.123456Z'),
        'capture' => CaptureMode::Usage,
        'sampled' => false,
        'model' => new Model(provider: 'provider-name', requested: 'requested-model'),
    ], $overrides));
}

it('constructs and decodes an unattributed failover without inventing an operation', function (): void {
    $constructed = unattributedFailoverRecord();
    $decoded = RecordV1::fromArray(unattributedFailoverPayload());

    expect($constructed->operation)->toBeNull()
        ->and($constructed->toArray())->not->toHaveKeys(['operation', 'invocation_id', 'attempt', 'parent_invocation_id', 'parent_tool_invocation_id'])
        ->and($decoded->operation)->toBeNull()
        ->and($decoded->model?->provider)->toBe('provider-name')
        ->and($decoded->model?->requested)->toBe('requested-model')
        ->and($decoded->toArray())->not->toHaveKey('operation');
});

it('round-trips an optional failover failure class and omits null', function (): void {
    $withFailure = RecordV1::fromArray(unattributedFailoverPayload([
        'failure_class' => RuntimeException::class,
    ]))->toArray();
    $withoutFailure = RecordV1::fromArray(unattributedFailoverPayload())->toArray();

    expect($withFailure['failure_class'])->toBe(RuntimeException::class)
        ->and($withoutFailure)->not->toHaveKey('failure_class');
});

it('requires provider and requested model values on unattributed failovers', function (array $model): void {
    expect(fn (): RecordV1 => RecordV1::fromArray(unattributedFailoverPayload(['model' => $model])))
        ->toThrow(InvalidEnvelope::class);
})->with([
    'missing provider' => [['requested' => 'requested-model']],
    'missing requested' => [['provider' => 'provider-name']],
    'empty provider' => [['provider' => '', 'requested' => 'requested-model']],
    'empty requested' => [['provider' => 'provider-name', 'requested' => '']],
]);

it('rejects attribution fields on an otherwise unattributed failover', function (array $field): void {
    expect(fn (): RecordV1 => RecordV1::fromArray(unattributedFailoverPayload($field)))
        ->toThrow(InvalidEnvelope::class);
})->with([
    'operation' => [['operation' => Operation::Agent->value]],
    'invocation id' => [['invocation_id' => 'run-1']],
    'attempt' => [['attempt' => 1]],
    'parent invocation id' => [['parent_invocation_id' => 'parent-1']],
    'parent tool invocation id' => [['parent_tool_invocation_id' => 'tool-1']],
]);

it('enforces failover attribution rules on constructed records', function (array $fields): void {
    expect(fn (): RecordV1 => unattributedFailoverRecord($fields))
        ->toThrow(InvalidEnvelope::class);
})->with([
    'operation' => [['operation' => Operation::Agent]],
    'attempt' => [['attempt' => 1]],
    'parent invocation id' => [['parentInvocationId' => 'parent-1']],
    'parent tool invocation id' => [['parentToolInvocationId' => 'tool-1']],
    'attributed without operation' => [['invocationId' => 'run-1', 'attempt' => 1]],
    'non-failover without operation' => [['type' => RecordType::RunStart]],
    'missing provider' => [['model' => new Model(requested: 'requested-model')]],
    'missing requested' => [['model' => new Model(provider: 'provider-name')]],
]);

it('requires operation on attributed failovers and every other record type', function (array $fields): void {
    expect(fn (): RecordV1 => RecordV1::fromArray(unattributedFailoverPayload($fields)))
        ->toThrow(InvalidEnvelope::class);
})->with([
    'attributed failover' => [['invocation_id' => 'run-1', 'attempt' => 1]],
    'run start' => [['type' => RecordType::RunStart->value]],
]);

it('rejects malformed known values and ignores unknown additive values', function (): void {
    expect(fn (): RecordV1 => RecordV1::fromArray(unattributedFailoverPayload(['operation' => 'future-operation'])))
        ->toThrow(InvalidEnvelope::class);

    $record = RecordV1::fromArray(unattributedFailoverPayload([
        'future_record' => true,
        'model' => [
            'provider' => 'provider-name',
            'requested' => 'requested-model',
            'future_model' => true,
        ],
    ]))->toArray();

    expect($record)->not->toHaveKey('future_record')
        ->and($record['model'])->not->toHaveKey('future_model');
});
