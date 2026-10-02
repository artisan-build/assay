<?php

declare(strict_types=1);

use App\Services\RetentionPruner;
use App\Services\SubjectErasure;
use App\Services\UsageIngestProcessor;
use ArtisanBuild\AssayContracts\UuidV7;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function ingestRetentionRun(string $invocation, string $at, string $content): void
{
    resolve(UsageIngestProcessor::class)->process('retention-app', $at, [
        'envelope_id' => (string) UuidV7::generate(),
        'sent_at' => $at,
        'client' => ['package' => 'artisan-build/assay-client', 'version' => '1.0.0'],
        'sources' => [['driver' => 'laravel-ai', 'package' => 'laravel/ai', 'version' => '1.0.1']],
        'environment' => 'testing',
        'deploy' => null,
        'dropped_transport_total' => 0,
        'dropped_hook_total' => 0,
        'records' => [[
            'record_id' => (string) UuidV7::generate(),
            'source' => 'laravel-ai',
            'type' => 'step.end',
            'operation' => 'agent',
            'invocation_id' => $invocation,
            'attempt' => 1,
            'step' => 0,
            'at' => $at,
            'capture' => 'full',
            'sampled' => true,
            'usage' => ['input_tokens' => 7],
            'duration_ms' => 1,
            'content' => json_encode(['output_text' => $content], JSON_THROW_ON_ERROR),
        ]],
    ]);
}

it('honors exact content and usage boundaries while preserving recent usage totals', function (): void {
    $asOf = CarbonImmutable::parse('2026-10-01T12:00:00.000000+00:00');
    ingestRetentionRun('content-boundary', '2026-09-01T12:00:00.000000+00:00', 'CONTENT-AT-BOUNDARY');
    ingestRetentionRun('content-newer', '2026-09-01T12:00:00.000001+00:00', 'CONTENT-JUST-NEWER');
    ingestRetentionRun('usage-boundary', '2025-09-01T12:00:00.000000+00:00', 'USAGE-AT-BOUNDARY');
    ingestRetentionRun('usage-newer', '2025-09-01T12:00:00.000001+00:00', 'USAGE-JUST-NEWER');

    $result = resolve(RetentionPruner::class)->prune($asOf, 100);
    $content = json_encode(DB::table('assay_record_content')->get(), JSON_THROW_ON_ERROR);

    expect($result['content_rows_deleted'])->toBe(3)
        ->and($result['usage_runs_deleted'])->toBe(1)
        ->and($content)->not->toContain('CONTENT-AT-BOUNDARY', 'USAGE-AT-BOUNDARY', 'USAGE-JUST-NEWER')
        ->toContain('CONTENT-JUST-NEWER')
        ->and(DB::table('assay_runs')->where('invocation_id', 'content-boundary')->exists())->toBeTrue()
        ->and(DB::table('assay_usage_metrics')->join('assay_runs', 'assay_runs.id', '=', 'assay_usage_metrics.run_id')->where('assay_runs.invocation_id', 'content-boundary')->value('value'))->toEqual(7)
        ->and(DB::table('assay_runs')->where('invocation_id', 'usage-boundary')->exists())->toBeFalse()
        ->and(DB::table('assay_runs')->where('invocation_id', 'usage-newer')->exists())->toBeTrue();
});

it('drains every eligible store to the retention boundary in bounded batches per scheduled invocation', function (): void {
    $asOf = CarbonImmutable::parse('2026-10-01T12:00:00.000000+00:00');
    ingestRetentionRun('scheduled-old-one', '2026-09-01T12:00:00.000000+00:00', 'SCHEDULED-OLD-ONE');
    ingestRetentionRun('scheduled-old-two', '2026-08-31T12:00:00.000000+00:00', 'SCHEDULED-OLD-TWO');
    ingestRetentionRun('scheduled-new', '2026-09-01T12:00:00.000001+00:00', 'SCHEDULED-NEW');

    $status = Artisan::call('assay:retention:prune', [
        '--as-of' => $asOf->format('Y-m-d\TH:i:s.uP'),
        '--limit' => '1',
    ]);

    expect($status)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('2 content rows')
        ->and(DB::table('assay_record_content')->count())->toBe(1)
        ->and((string) DB::table('assay_record_content')->value('content'))->toContain('SCHEDULED-NEW')
        ->and(DB::table('assay_usage_metrics')->sum('value'))->toEqual(21);
});

it('expires usage metadata without weakening erasure records or the object journal', function (): void {
    Storage::fake('retention-journal');
    config()->set('assay.erasure.journal_disk', 'retention-journal');
    config()->set('assay.erasure.journal_prefix', 'journal');
    config()->set('assay.erasure.active_key_version', 'v1');
    config()->set('assay.erasure.keys', ['v1' => 'retention-erasure-key-32-bytes!!']);
    ingestRetentionRun('expired-erased-run', '2025-09-01T12:00:00.000000+00:00', 'EXPIRED-ERASED-CONTENT');
    DB::table('assay_runs')->where('invocation_id', 'expired-erased-run')->update(['subject' => 'expired-erased-subject']);
    $appId = (string) DB::table('assay_apps')->where('app_ref', 'retention-app')->value('id');
    resolve(SubjectErasure::class)->erase($appId, 'expired-erased-subject', CarbonImmutable::parse('2026-10-01T11:00:00.000000+00:00'));

    resolve(RetentionPruner::class)->prune(CarbonImmutable::parse('2026-10-01T12:00:00.000000+00:00'), 100);

    expect(DB::table('assay_runs')->where('invocation_id', 'expired-erased-run')->exists())->toBeFalse()
        ->and(DB::table('assay_erasure_records')->where('app_id', $appId)->count())->toBe(1)
        ->and(Storage::disk('retention-journal')->allFiles('journal'))->toHaveCount(1);
});

it('validates retention limits and configuration and schedules without overlap', function (): void {
    expect(Artisan::call('assay:retention:prune', ['--limit' => 'invalid']))->toBe(Command::INVALID)
        ->and(Artisan::output())->toContain('positive integer');

    config()->set('assay.retention.run_content_days', 0);

    expect(Artisan::call('assay:retention:prune', ['--as-of' => '2026-10-01T12:00:00+00:00']))->toBe(Command::INVALID)
        ->and(Artisan::output())->toContain('configuration or command options are invalid');

    config()->set('assay.retention.run_content_days', 30);
    config()->set('assay.retention.dataset_days', 396);

    expect(Artisan::call('assay:retention:prune', ['--as-of' => '2026-10-01T12:00:00+00:00']))->toBe(Command::INVALID)
        ->and(Artisan::output())->toContain('configuration or command options are invalid');

    config()->set('assay.retention.dataset_days', null);

    expect(Artisan::call('assay:retention:prune', ['--as-of' => '2026-10-01T12:00:00+00:00']))->toBe(Command::INVALID)
        ->and(Artisan::output())->toContain('configuration or command options are invalid');

    config()->set('assay.retention.dataset_days', 395);

    expect(Artisan::call('assay:retention:prune', ['--as-of' => '2026-10-01T12:00:00+00:00']))->toBe(Command::SUCCESS);

    config()->set('assay.retention.dataset_days', 365);

    $event = collect(resolve(Schedule::class)->events())
        ->first(static fn ($event): bool => str_contains((string) $event->command, 'assay:retention:prune'));

    expect($event)->not->toBeNull()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and(config('assay.retention.dataset_days'))->toBe(365);
});
