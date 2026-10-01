<?php

declare(strict_types=1);

namespace Tests\Support;

use ArtisanBuild\AssayContracts\Client;
use ArtisanBuild\AssayContracts\EnvelopeV1;
use ArtisanBuild\AssayContracts\RecordV1;
use ArtisanBuild\AssayContracts\Source;
use ArtisanBuild\AssayContracts\Timestamp;
use ArtisanBuild\AssayContracts\UuidV7;

final class EnvelopeFactory
{
    /**
     * @param  list<RecordV1>  $records
     */
    public static function envelope(
        array $records,
        int $transportDrops = 0,
        int $hookDrops = 0,
        string $sentAt = '2026-10-01T12:00:00.000000+00:00',
    ): EnvelopeV1 {
        return new EnvelopeV1(
            envelopeId: UuidV7::generate(),
            sentAt: new Timestamp($sentAt),
            client: new Client('artisan-build/assay-client', '1.0.0'),
            sources: [new Source('laravel-ai', 'laravel/ai', '1.0.1')],
            environment: 'testing',
            droppedTransportTotal: $transportDrops,
            droppedHookTotal: $hookDrops,
            records: $records,
            deploy: 'test-deploy',
        );
    }

    /** @param array<string, mixed> $overrides */
    public static function record(array $overrides = []): RecordV1
    {
        $data = array_replace([
            'record_id' => (string) UuidV7::generate(),
            'source' => 'laravel-ai',
            'type' => 'run.start',
            'operation' => 'agent',
            'invocation_id' => 'run-1',
            'attempt' => 1,
            'at' => '2026-10-01T12:00:00.000000+00:00',
            'capture' => 'usage',
            'sampled' => true,
        ], $overrides);

        return RecordV1::fromArray(array_filter($data, static fn (mixed $value): bool => $value !== null));
    }
}
