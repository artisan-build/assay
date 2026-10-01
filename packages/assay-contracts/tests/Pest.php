<?php

declare(strict_types=1);
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Client;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\EnvelopeV1;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\Source;
use ArtisanBuild\AssayContracts\Timestamp;
use ArtisanBuild\AssayContracts\UuidV7;

uses()->in(__DIR__);

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

    return EnvelopeCodec::decode(
        EnvelopeCodec::encode($envelope),
    )->records[0];
}
