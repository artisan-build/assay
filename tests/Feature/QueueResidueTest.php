<?php

declare(strict_types=1);

use App\Jobs\ProcessUsageEnvelope;
use App\Services\QueueResiduePruner;
use ArtisanBuild\AssayContracts\UuidV7;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EnvelopeFactory;

function queueResidueJob(string $queue, string $subject, string $content): object
{
    $job = ProcessUsageEnvelope::fromContract(
        'queue-residue-app',
        '2026-10-01T12:00:00.000000+00:00',
        EnvelopeFactory::envelope([
            EnvelopeFactory::record([
                'invocation_id' => (string) UuidV7::generate(),
                'subject' => $subject,
                'capture' => 'full',
                'content' => ['instructions' => $content],
            ]),
        ]),
    );

    Queue::connection('database')->push($job, queue: $queue);

    return DB::table('jobs')->where('queue', $queue)->sole();
}

function failQueueResidue(object $queued, string $queue): object
{
    /** @var FailedJobProviderInterface $failedJobs */
    $failedJobs = resolve('queue.failer');
    $failedJobs->log('database', $queue, (string) $queued->payload, new RuntimeException('value-free ingest failure'));

    return DB::table('failed_jobs')->where('queue', $queue)->sole();
}

it('prunes encrypted pending and failed ingest residue at the exact 72 hour boundary in bounded batches', function (): void {
    $asOf = CarbonImmutable::parse('2026-10-01T12:00:00.000000+00:00');
    $boundary = $asOf->subHours(72);
    $subject = 'QUEUE-RESIDUE-SUBJECT-CANARY';
    $content = 'QUEUE-RESIDUE-CONTENT-CANARY';
    $oldOne = queueResidueJob('residue-old-one', $subject, $content);
    $oldTwo = queueResidueJob('residue-old-two', $subject, $content);
    $new = queueResidueJob('residue-new', $subject, $content);
    $failedOldOne = failQueueResidue($oldOne, 'failed-residue-old-one');
    $failedOldTwo = failQueueResidue($oldTwo, 'failed-residue-old-two');
    $failedNew = failQueueResidue($new, 'failed-residue-new');
    DB::table('jobs')->whereIn('id', [$oldOne->id, $oldTwo->id])->update(['created_at' => $boundary->timestamp]);
    DB::table('jobs')->where('id', $new->id)->update(['created_at' => $boundary->addMicrosecond()->timestamp + 1]);
    DB::table('failed_jobs')->whereIn('id', [$failedOldOne->id, $failedOldTwo->id])->update(['failed_at' => $boundary->format('Y-m-d H:i:s.uP')]);
    DB::table('failed_jobs')->where('id', $failedNew->id)->update(['failed_at' => $boundary->addSecond()->format('Y-m-d H:i:s.uP')]);
    DB::table('jobs')->insert([
        'queue' => 'unrelated',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\UnrelatedJob'], JSON_THROW_ON_ERROR),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $boundary->timestamp,
        'created_at' => $boundary->timestamp,
    ]);

    $result = resolve(QueueResiduePruner::class)->prune($asOf, 1);
    $surfaces = (string) $new->payload.(string) $failedNew->payload.(string) $failedNew->exception;

    expect($result)->toBe(['pending_jobs_deleted' => 2, 'failed_jobs_deleted' => 2, 'maximum_hours' => 72])
        ->and(DB::table('jobs')->pluck('queue')->sort()->values()->all())->toBe(['residue-new', 'unrelated'])
        ->and(DB::table('failed_jobs')->pluck('queue')->all())->toBe(['failed-residue-new'])
        ->and($surfaces)->not->toContain($subject, $content)
        ->and((string) $failedNew->exception)->toContain('value-free ingest failure');
});

it('uses a configured shorter residue window and caps longer values at content retention', function (int $configured, int $contentDays, int $effective): void {
    config()->set('assay.queue.failed_retention_hours', $configured);
    config()->set('assay.retention.run_content_days', $contentDays);
    $asOf = CarbonImmutable::parse('2026-10-01T12:00:00.000000+00:00');
    $boundary = $asOf->subHours($effective);
    $old = queueResidueJob('configured-old', 'configured-subject', 'configured-content');
    $new = queueResidueJob('configured-new', 'configured-subject', 'configured-content');
    $failedOld = failQueueResidue($old, 'configured-failed-old');
    $failedNew = failQueueResidue($new, 'configured-failed-new');
    DB::table('jobs')->where('id', $old->id)->update(['created_at' => $boundary->timestamp]);
    DB::table('jobs')->where('id', $new->id)->update(['created_at' => $boundary->addSecond()->timestamp]);
    DB::table('failed_jobs')->where('id', $failedOld->id)->update(['failed_at' => $boundary->format('Y-m-d H:i:s.uP')]);
    DB::table('failed_jobs')->where('id', $failedNew->id)->update(['failed_at' => $boundary->addSecond()->format('Y-m-d H:i:s.uP')]);

    $result = resolve(QueueResiduePruner::class)->prune($asOf, 100);

    expect($result['maximum_hours'])->toBe($effective)
        ->and(DB::table('jobs')->where('id', $old->id)->exists())->toBeFalse()
        ->and(DB::table('jobs')->where('id', $new->id)->exists())->toBeTrue()
        ->and(DB::table('failed_jobs')->where('id', $failedOld->id)->exists())->toBeFalse()
        ->and(DB::table('failed_jobs')->where('id', $failedNew->id)->exists())->toBeTrue();
})->with([
    'configured shorter window' => [24, 30, 24],
    'content retention cap' => [1_000, 1, 24],
]);

it('schedules bounded queue residue pruning without overlap', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(static fn ($event): bool => str_contains((string) $event->command, 'assay:queue:prune-residue'));

    expect($event)->not->toBeNull()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expression)->toBe('* * * * *')
        ->and(Artisan::call('assay:queue:prune-residue', ['--limit' => 'invalid']))->toBe(2);
});
