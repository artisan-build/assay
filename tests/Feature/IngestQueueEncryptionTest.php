<?php

declare(strict_types=1);

use App\Exceptions\ContentPersistenceFailed;
use App\Jobs\ProcessUsageEnvelope;
use App\Services\UsageIngestProcessor;
use ArtisanBuild\AssayContracts\UuidV7;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
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

it('replaces content database failures with value-free exceptions in direct queue and log surfaces', function (string $failure, string $sqlState): void {
    $canary = $failure === 'null'
        ? 'NULL-ORIGIN-CONTENT-CANARY'
        : 'CONSTRAINT-CONTENT-CANARY';
    $databaseDiagnostic = $failure === 'null'
        ? 'unsupported Unicode escape sequence'
        : 'violates check constraint';
    $recordId = (string) UuidV7::generate();

    if ($failure === 'constraint') {
        DB::statement(<<<'SQL'
            ALTER TABLE assay_record_content
            ADD CONSTRAINT assay_test_reject_content CHECK ((content->>'output_text') <> 'CONSTRAINT-CONTENT-CANARY')
            SQL);
    }

    $job = static fn (string $app): ProcessUsageEnvelope => ProcessUsageEnvelope::fromContract(
        $app,
        '2026-10-01T12:00:00.000000+00:00',
        EnvelopeFactory::envelope([
            EnvelopeFactory::record([
                'record_id' => $recordId,
                'type' => 'step.end',
                'capture' => 'full',
                'step' => 0,
                'content' => ['output_text' => $canary.($failure === 'null' ? "\0suffix" : '')],
            ]),
        ]),
    );
    $direct = null;

    try {
        $job('direct-'.$failure.'-failure-app')->handle(resolve(UsageIngestProcessor::class));
    } catch (Throwable $caught) {
        $direct = $caught;
    }

    expect($direct)->toBeInstanceOf(ContentPersistenceFailed::class)
        ->and($direct?->recordId)->toBe($recordId)
        ->and($direct?->sqlState)->toBe($sqlState)
        ->and($direct?->getMessage())->toContain($recordId, $sqlState)
        ->not->toContain($canary, $databaseDiagnostic, 'Connection:', 'insert into')
        ->and((string) $direct)->not->toContain($canary, $databaseDiagnostic, 'Connection:', 'insert into')
        ->and($direct?->getPrevious())->toBeNull()
        ->and(DB::connection()->getConfig('mask_bindings_in_exception_messages'))->toBeTrue();

    $logPath = storage_path('logs/content-persistence-'.$failure.'.log');
    @unlink($logPath);
    config()->set('logging.default', 'single');
    config()->set('logging.channels.single.path', $logPath);
    Log::setDefaultDriver('single');
    Log::forgetChannel('single');
    $logs = [];
    Log::listen(static function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event->message;

        foreach ($event->context as $value) {
            if ($value instanceof Throwable) {
                $logs[] = (string) $value;
            } elseif (is_scalar($value)) {
                $logs[] = (string) $value;
            }
        }
    });

    $queue = 'content-'.$failure.'-failure';
    Queue::connection('database')->push($job('queued-'.$failure.'-failure-app'), queue: $queue);
    Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => $queue,
        '--once' => true,
        '--tries' => 1,
    ]);

    $failed = DB::table('failed_jobs')->where('queue', $queue)->sole();
    $logOutput = is_file($logPath) ? (string) file_get_contents($logPath) : '';

    expect((string) $failed->payload)->not->toContain($canary)
        ->and((string) $failed->exception)->toContain(ContentPersistenceFailed::class, $recordId, $sqlState)
        ->not->toContain($canary, $databaseDiagnostic, 'Connection:', 'insert into')
        ->and($logs)->not->toBeEmpty()
        ->and(implode("\n", $logs))->toContain(ContentPersistenceFailed::class, $recordId, $sqlState)
        ->not->toContain($canary, $databaseDiagnostic, 'Connection:', 'insert into')
        ->and($logOutput)->toContain('Content persistence failed', $recordId, $sqlState)
        ->not->toContain($canary, $databaseDiagnostic, 'Connection:', 'insert into');

    @unlink($logPath);
})->with([
    'null-origin PostgreSQL JSONB failure' => ['null', '22P05'],
    'content constraint violation' => ['constraint', '23514'],
]);
