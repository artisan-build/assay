<?php

declare(strict_types=1);

use App\Services\DatasetExporter;
use App\Services\DatasetManager;
use App\Services\SubjectErasure;
use App\Services\SubjectErasureBarrier;
use App\Services\UsageIngestProcessor;
use ArtisanBuild\AssayContracts\UuidV7;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** @return array{app_id: string, run_id: string, subject: string} */
function seedDatasetRaceRun(string $invocation, string $subject, bool $inherited = false): array
{
    $records = [[
        'record_id' => (string) UuidV7::generate(),
        'source' => 'laravel-ai',
        'type' => 'run.start',
        'operation' => 'agent',
        'invocation_id' => $inherited ? $invocation.'-root' : $invocation,
        'attempt' => 1,
        'at' => '2026-10-01T12:00:00.000000+00:00',
        'capture' => 'full',
        'sampled' => true,
        'subject' => $subject,
        'agent' => 'App\\Ai\\RaceAgent',
        'content' => json_encode(['instructions' => 'RACE-ROOT-CONTENT'], JSON_THROW_ON_ERROR),
    ]];

    if ($inherited) {
        $records[] = [
            'record_id' => (string) UuidV7::generate(),
            'source' => 'laravel-ai',
            'type' => 'run.start',
            'operation' => 'agent',
            'invocation_id' => $invocation,
            'parent_invocation_id' => $invocation.'-root',
            'attempt' => 1,
            'at' => '2026-10-01T12:00:01.000000+00:00',
            'capture' => 'full',
            'sampled' => true,
            'agent' => 'App\\Ai\\RaceAgent',
            'content' => json_encode(['instructions' => 'RACE-CONTENT-CANARY'], JSON_THROW_ON_ERROR),
        ];
    } else {
        $records[0]['content'] = json_encode(['instructions' => 'RACE-CONTENT-CANARY'], JSON_THROW_ON_ERROR);
    }

    resolve(UsageIngestProcessor::class)->process('dataset-race-app', '2026-10-01T12:01:00.000000+00:00', [
        'envelope_id' => (string) UuidV7::generate(),
        'sent_at' => '2026-10-01T12:00:00.000000+00:00',
        'client' => ['package' => 'artisan-build/assay-client', 'version' => '1.0.0'],
        'sources' => [['driver' => 'laravel-ai', 'package' => 'laravel/ai', 'version' => '1.0.1']],
        'environment' => 'testing',
        'deploy' => 'race-deploy',
        'dropped_transport_total' => 0,
        'dropped_hook_total' => 0,
        'records' => $records,
    ]);

    return [
        'app_id' => (string) DB::table('assay_apps')->where('app_ref', 'dataset-race-app')->value('id'),
        'run_id' => (string) DB::table('assay_runs')->where('invocation_id', $invocation)->value('id'),
        'subject' => $subject,
    ];
}

function datasetRaceProcess(string ...$arguments): Process
{
    return new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-dataset.php'), ...$arguments]);
}

beforeEach(function (): void {
    expect(Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]))->toBe(0);
    Storage::disk('local')->deleteDirectory('assay/erasures');
});

afterEach(function (): void {
    Storage::disk('local')->deleteDirectory('assay/erasures');
});

it('serializes erasure first so a racing dataset add cannot snapshot erased content', function (): void {
    $source = seedDatasetRaceRun('erasure-first-add', 'erasure-first-add-subject');
    $dataset = resolve(DatasetManager::class)->create('Erasure first');

    DB::beginTransaction();
    resolve(SubjectErasureBarrier::class)->lock($source['app_id'], $source['subject']);
    resolve(SubjectErasure::class)->erase(
        $source['app_id'],
        $source['subject'],
        CarbonImmutable::parse('2026-10-01T12:05:00+00:00'),
    );
    $add = datasetRaceProcess('add', $dataset['id'], $source['run_id']);
    $add->start();
    Sleep::usleep(300_000);
    expect($add->isRunning())->toBeTrue('Dataset add did not wait for the erasure subject lock.');
    DB::commit();
    $add->wait();

    expect($add->isSuccessful())->toBeTrue($add->getErrorOutput())
        ->and($add->getOutput())->toStartWith('blocked:')
        ->and(DB::table('assay_dataset_items')->count())->toBe(0)
        ->and(json_encode(DB::table('assay_dataset_items')->get(), JSON_THROW_ON_ERROR))->not->toContain('RACE-CONTENT-CANARY');
});

it('serializes dataset add first and the waiting erasure removes its snapshot', function (): void {
    $source = seedDatasetRaceRun('add-first', 'add-first-subject', inherited: true);
    $dataset = resolve(DatasetManager::class)->create('Add first');

    DB::beginTransaction();
    resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);
    $erasure = new Process([
        PHP_BINARY, base_path('tests/Fixtures/concurrent-erasure.php'),
        $source['app_id'], $source['subject'], '2026-10-01T12:05:00+00:00',
    ]);
    $erasure->start();
    Sleep::usleep(300_000);
    expect($erasure->isRunning())->toBeTrue('Erasure did not wait for the dataset-add subject lock.');
    DB::commit();
    $erasure->wait();

    expect($erasure->isSuccessful())->toBeTrue($erasure->getErrorOutput())
        ->and(DB::table('assay_dataset_items')->count())->toBe(0);
});

it('serializes export rendering on both sides of erasure and later rendering reads post-erasure live rows', function (): void {
    $owner = User::query()->create([
        'name' => 'Race Export Owner',
        'email' => Str::random(8).'@example.test',
    ]);
    $owner->forceFill(['role' => UserRole::Owner->value, 'status' => 'active'])->save();
    $principal = ActingPrincipal::local('web', $owner);
    $source = seedDatasetRaceRun('export-race', 'export-race-subject');
    $dataset = resolve(DatasetManager::class)->create('Export race', null);
    resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);
    $request = resolve(DatasetExporter::class)->request($dataset['id'], $principal);

    DB::beginTransaction();
    resolve(SubjectErasureBarrier::class)->lock($source['app_id'], $source['subject']);
    resolve(SubjectErasure::class)->erase(
        $source['app_id'],
        $source['subject'],
        CarbonImmutable::parse('2026-10-01T12:05:00+00:00'),
    );
    $render = datasetRaceProcess('render', $request['id'], (string) $owner->getKey());
    $render->start();
    Sleep::usleep(300_000);
    expect($render->isRunning())->toBeTrue('Export did not wait for the erasure subject lock.');
    DB::commit();
    $render->wait();

    expect($render->isSuccessful())->toBeTrue($render->getErrorOutput())
        ->and($render->getOutput())->toBe('')
        ->and(resolve(DatasetExporter::class)->render($request['id'], $principal))->toBe('');
});

it('allows an export ordered first while the waiting erasure removes all later live output', function (): void {
    $owner = User::query()->create([
        'name' => 'First Export Owner',
        'email' => Str::random(8).'@example.test',
    ]);
    $owner->forceFill(['role' => UserRole::Owner->value, 'status' => 'active'])->save();
    $principal = ActingPrincipal::local('web', $owner);
    $source = seedDatasetRaceRun('export-first', 'export-first-subject');
    $dataset = resolve(DatasetManager::class)->create('Export first', null);
    resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);
    $request = resolve(DatasetExporter::class)->request($dataset['id'], $principal);

    DB::beginTransaction();
    resolve(SubjectErasureBarrier::class)->lock($source['app_id'], $source['subject']);
    $erasure = new Process([
        PHP_BINARY, base_path('tests/Fixtures/concurrent-erasure.php'),
        $source['app_id'], $source['subject'], '2026-10-01T12:05:00+00:00',
    ]);
    $erasure->start();
    Sleep::usleep(300_000);
    expect($erasure->isRunning())->toBeTrue('Erasure did not wait for the export subject lock.');
    $before = resolve(DatasetExporter::class)->render($request['id'], $principal);
    DB::commit();
    $erasure->wait();

    expect($erasure->isSuccessful())->toBeTrue($erasure->getErrorOutput())
        ->and($before)->toContain('RACE-CONTENT-CANARY')
        ->and(DB::table('assay_dataset_items')->count())->toBe(0)
        ->and(resolve(DatasetExporter::class)->render($request['id'], $principal))->toBe('');
});
