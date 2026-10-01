<?php

declare(strict_types=1);

use App\Jobs\ProcessUsageEnvelope;
use App\Services\UsageIngestProcessor;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EnvelopeFactory;

it('encrypts full content in database queue and failure payloads', function (): void {
    $canary = 'FULL-CONTENT-QUEUE-CANARY';
    $job = ProcessUsageEnvelope::fromContract(
        'encrypted-ingest-app',
        '2026-10-01T12:00:00.000000+00:00',
        EnvelopeFactory::envelope([
            EnvelopeFactory::record([
                'type' => 'step.end',
                'capture' => 'full',
                'step' => 0,
                'content' => [
                    'output_text' => $canary,
                    'structured_output' => ['empty_object' => (object) [], 'empty_list' => []],
                ],
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
        ->and($restored->envelope['records'][0]['content'])
        ->toBe('{"output_text":"FULL-CONTENT-QUEUE-CANARY","structured_output":{"empty_object":{},"empty_list":[]}}');

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

it('masks content bindings in PostgreSQL exceptions logs and database failed jobs', function (): void {
    $canary = 'CONTENT-WRITE-FAILURE-CANARY';
    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION assay_reject_record_content()
        RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'forced content write failure';
        END;
        $$ LANGUAGE plpgsql
        SQL);
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER assay_reject_record_content
        BEFORE INSERT ON assay_record_content
        FOR EACH ROW EXECUTE FUNCTION assay_reject_record_content()
        SQL);

    $job = static fn (string $app): ProcessUsageEnvelope => ProcessUsageEnvelope::fromContract(
        $app,
        '2026-10-01T12:00:00.000000+00:00',
        EnvelopeFactory::envelope([
            EnvelopeFactory::record([
                'type' => 'step.end',
                'capture' => 'full',
                'step' => 0,
                'content' => ['output_text' => $canary],
            ]),
        ]),
    );

    $exception = null;

    try {
        $job('direct-failure-app')->handle(resolve(UsageIngestProcessor::class));
    } catch (QueryException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception?->getMessage())->not->toContain($canary)
        ->and(DB::connection()->getConfig('mask_bindings_in_exception_messages'))->toBeTrue();

    $logs = [];
    Log::listen(static function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event->message;

        foreach ($event->context as $value) {
            if ($value instanceof Throwable) {
                $logs[] = $value->getMessage();
            } elseif (is_scalar($value)) {
                $logs[] = (string) $value;
            }
        }
    });

    Queue::connection('database')->push($job('queued-failure-app'), queue: 'content-write-failure');
    Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => 'content-write-failure',
        '--once' => true,
        '--tries' => 1,
    ]);

    $failed = DB::table('failed_jobs')->where('queue', 'content-write-failure')->sole();

    expect((string) $failed->payload)->not->toContain($canary)
        ->and((string) $failed->exception)->not->toContain($canary)
        ->and((string) $failed->exception)->toContain('forced content write failure')
        ->and($logs)->not->toBeEmpty()
        ->and(implode("\n", $logs))->not->toContain($canary);
});
