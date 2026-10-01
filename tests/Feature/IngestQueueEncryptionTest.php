<?php

declare(strict_types=1);

use App\Jobs\ProcessUsageEnvelope;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EnvelopeFactory;

it('encrypts full content in database queue and failure payloads', function (): void {
    $canary = 'FULL-CONTENT-QUEUE-CANARY';
    $job = ProcessUsageEnvelope::fromContract(
        'encrypted-ingest-app',
        '2026-10-01T12:00:00.000000+00:00',
        EnvelopeFactory::envelope([
            EnvelopeFactory::record([
                'capture' => 'full',
                'content' => ['instructions' => $canary],
            ]),
        ]),
    );

    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class);

    Queue::connection('database')->push($job, queue: 'encrypted-ingest');

    $payload = (string) DB::table('jobs')
        ->where('queue', 'encrypted-ingest')
        ->sole()
        ->payload;
    /** @var array{data: array{command: string}} $decoded */
    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $command = $decoded['data']['command'];

    expect($payload)->not->toContain($canary)
        ->and($command)->not->toStartWith('O:')
        ->not->toContain(ProcessUsageEnvelope::class);

    $serialized = resolve(Encrypter::class)->decrypt($command);
    $restored = unserialize($serialized, ['allowed_classes' => [ProcessUsageEnvelope::class]]);

    expect($restored)->toBeInstanceOf(ProcessUsageEnvelope::class);

    if (! $restored instanceof ProcessUsageEnvelope) {
        throw new RuntimeException('Encrypted queue command did not contain the expected job.');
    }

    expect($restored->appRef)->toBe('encrypted-ingest-app')
        ->and($restored->receivedAt)->toBe('2026-10-01T12:00:00.000000+00:00')
        ->and($restored->envelope['records'][0]['content'])->toBe(['instructions' => $canary]);

    foreach (Arr::flatten($restored->envelope) as $value) {
        expect(is_scalar($value) || $value === null)->toBeTrue();
    }

    /** @var FailedJobProviderInterface $failedJobs */
    $failedJobs = resolve('queue.failer');
    $failedJobs->log('database', 'encrypted-ingest', $payload, new RuntimeException('forced failure'));
    $failedPayload = (string) DB::table('failed_jobs')->sole()->payload;

    expect($failedPayload)->toBe($payload)
        ->not->toContain($canary)
        ->and(DB::table('assay_record_content')->count())->toBe(0)
        ->and(DB::table('assay_messages')->count())->toBe(0);
});
