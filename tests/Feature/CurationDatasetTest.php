<?php

declare(strict_types=1);

use App\Authorization\AssayAccessPolicy;
use App\Enums\ContentAccess;
use App\Models\ContentAccessOverride;
use App\Services\DatasetExporter;
use App\Services\DatasetManager;
use App\Services\RetentionPruner;
use App\Services\RunCuration;
use App\Services\SubjectErasure;
use App\Services\SubjectErasureHasher;
use App\Services\UsageIngestProcessor;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\DelegatedClaims;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\EnvelopeFactory;

uses(WithCredentials::class);

function createPr7User(string $name, UserRole $role): User
{
    $user = User::query()->create([
        'name' => $name,
        'email' => Str::slug($name).'-'.Str::lower(Str::random(8)).'@example.test',
    ]);
    $user->forceFill(['role' => $role->value, 'status' => 'active'])->save();

    return $user;
}

/** @return array{run_id: string, app_id: string} */
function ingestPr7Run(
    string $invocation,
    ?string $subject,
    string $suffix = 'ONE',
    bool $omissionsPresent = false,
    ?string $parentInvocation = null,
    string $appRef = 'pr7-app',
): array {
    $message = ['role' => 'user', 'text' => "INPUT-{$suffix}"];
    $hash = hash('sha256', json_encode($message, JSON_THROW_ON_ERROR));
    $start = [
        'invocation_id' => $invocation,
        'capture' => 'full',
        'sampled' => true,
        'agent' => 'App\\Ai\\DatasetAgent',
        'model' => ['provider' => 'provider-'.$suffix, 'requested' => 'requested-'.$suffix],
        'content' => [
            'instructions' => "INSTRUCTIONS-{$suffix}",
            'tools' => [['name' => 'lookup', 'description' => "TOOL-{$suffix}", 'parameters' => ['type' => 'object']]],
        ],
    ];

    if ($subject !== null) {
        $start['subject'] = $subject;
    }

    if ($parentInvocation !== null) {
        $start['parent_invocation_id'] = $parentInvocation;
    }

    $records = [
        EnvelopeFactory::record($start),
        EnvelopeFactory::record([
            'type' => 'step.start', 'invocation_id' => $invocation, 'step' => 0, 'capture' => 'full',
            'content' => ['message_hashes' => [$hash], 'new_messages' => [$hash => $message]],
        ]),
        EnvelopeFactory::record([
            'type' => 'step.end', 'invocation_id' => $invocation, 'step' => 0, 'capture' => 'full',
            'usage' => ['input_tokens' => 7], 'duration_ms' => 4.0,
            'model' => ['provider' => 'provider-'.$suffix, 'requested' => 'requested-'.$suffix, 'responded' => 'responded-'.$suffix],
            'content' => [
                'output_text' => "OUTPUT-{$suffix}",
                'structured_output' => ['answer' => $suffix],
                'tool_calls' => [['id' => 'call-1', 'name' => 'lookup', 'arguments' => ['id' => 1]]],
            ],
        ]),
        EnvelopeFactory::record([
            'type' => 'run.end', 'invocation_id' => $invocation, 'outcome' => 'completed', 'capture' => 'full',
            'usage' => ['input_tokens' => 7],
            'model' => ['provider' => 'provider-'.$suffix, 'requested' => 'requested-'.$suffix, 'responded' => 'responded-'.$suffix],
            'replay_inputs_omitted' => $omissionsPresent ? ['attachments'] : null,
        ]),
    ];
    $envelope = EnvelopeFactory::envelope($records);
    resolve(UsageIngestProcessor::class)->process($appRef, '2026-10-01T12:10:00.000000+00:00', [
        ...$envelope->toArray(),
        'records' => array_map(static function ($record): array {
            $data = $record->toArray();

            if ($record->content !== null) {
                $data['content'] = $record->content->toJson();
            }

            return $data;
        }, $records),
    ]);

    $appId = (string) DB::table('assay_apps')->where('app_ref', $appRef)->value('id');

    return [
        'run_id' => (string) DB::table('assay_runs')->where('app_id', $appId)->where('invocation_id', $invocation)->value('id'),
        'app_id' => $appId,
    ];
}

function ingestPr7LateSubject(string $invocation, string $subject, string $appRef = 'pr7-app'): void
{
    $record = EnvelopeFactory::record([
        'type' => 'run.start',
        'invocation_id' => $invocation,
        'capture' => 'full',
        'sampled' => true,
        'subject' => $subject,
        'agent' => 'App\\Ai\\DatasetAgent',
    ]);
    $envelope = EnvelopeFactory::envelope([$record]);
    resolve(UsageIngestProcessor::class)->process($appRef, '2026-10-01T12:20:00.000000+00:00', [
        ...$envelope->toArray(),
        'records' => [$record->toArray()],
    ]);
}

function agePr7Run(string $runId, CarbonImmutable $occurredAt): void
{
    $timestamp = $occurredAt->format('Y-m-d H:i:s.uP');
    DB::table('assay_runs')->where('id', $runId)->update([
        'started_at' => $timestamp,
        'ended_at' => $timestamp,
        'earliest_received_at' => $timestamp,
    ]);
    DB::table('assay_records')->where('run_id', $runId)->update(['occurred_at' => $timestamp]);
}

beforeEach(function (): void {
    Storage::fake('pr7-erasure');
    config()->set('assay.erasure.journal_disk', 'pr7-erasure');
    config()->set('assay.erasure.journal_prefix', 'journal');
    config()->set('assay.erasure.active_key_version', 'v1');
    config()->set('assay.erasure.keys', ['v1' => 'PR7-ERASURE-KEY-IS-AT-LEAST-32-BYTES']);
});

it('round-trips flags and freezes every dataset field independently of later source changes', function (): void {
    $source = ingestPr7Run('snapshot-run', 'snapshot-subject');
    $curation = resolve(RunCuration::class);
    $curation->flag($source['run_id'], ['support', 'gold'], 5, 'NOTE-ONE');
    $dataset = resolve(DatasetManager::class)->create('Training set');
    $item = resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);

    expect($curation->get($source['run_id']))->toBe(['labels' => ['support', 'gold'], 'rating' => 5, 'note' => 'NOTE-ONE'])
        ->and(array_keys($item['snapshot']))->toBe([
            'agent_class', 'provider', 'requested_model', 'instructions', 'input_messages', 'tool_schemas',
            'observed_output', 'labels', 'rating', 'note', 'source_client_version', 'deploy', 'capture',
            'sampled', 'hook_applied', 'replay_fidelity',
        ])
        ->and($item['snapshot']['agent_class'])->toBe('App\\Ai\\DatasetAgent')
        ->and($item['snapshot']['provider'])->toBe('provider-ONE')
        ->and($item['snapshot']['requested_model'])->toBe('requested-ONE')
        ->and($item['snapshot']['instructions'])->toBe('INSTRUCTIONS-ONE')
        ->and($item['snapshot']['input_messages'][0]['text'])->toBe('INPUT-ONE')
        ->and($item['snapshot']['tool_schemas'][0]['description'])->toBe('TOOL-ONE')
        ->and($item['snapshot']['observed_output']['text'])->toBe('OUTPUT-ONE')
        ->and($item['snapshot']['observed_output']['structured']['answer'])->toBe('ONE')
        ->and($item['snapshot']['labels'])->toBe(['support', 'gold'])
        ->and($item['snapshot']['rating'])->toBe(5)
        ->and($item['snapshot']['note'])->toBe('NOTE-ONE')
        ->and($item['snapshot']['source_client_version'])->toBe('1.0.0')
        ->and($item['snapshot']['deploy'])->toBe('test-deploy')
        ->and($item['snapshot']['capture'])->toBe('full')
        ->and($item['snapshot']['sampled'])->toBeTrue()
        ->and($item['snapshot']['hook_applied'])->toBeTrue()
        ->and($item['snapshot']['replay_fidelity'])->toBe('complete');

    $curation->flag($source['run_id'], ['changed'], 'fail', 'NOTE-TWO');
    DB::table('assay_runs')->where('id', $source['run_id'])->update(['agent' => 'App\\Ai\\ChangedAgent']);
    DB::table('assay_record_content')->where('run_id', $source['run_id'])->delete();
    $frozen = resolve(DatasetManager::class)->dataset($dataset['id'])['items'][0]['snapshot'];

    expect($frozen)->toEqual($item['snapshot']);
});

it('rejects incomplete content and derives replay fidelity only from omission presence', function (): void {
    $complete = ingestPr7Run('complete-run', 'fidelity-subject-a');
    $partial = ingestPr7Run('partial-run', 'fidelity-subject-b', 'TWO', true);
    $dataset = resolve(DatasetManager::class)->create('Fidelity set');

    expect(resolve(DatasetManager::class)->add($dataset['id'], $complete['run_id'])['snapshot']['replay_fidelity'])->toBe('complete')
        ->and(resolve(DatasetManager::class)->add($dataset['id'], $partial['run_id'])['snapshot']['replay_fidelity'])->toBe('partial');

    $incomplete = ingestPr7Run('incomplete-run', 'fidelity-subject-c', 'THREE');
    DB::table('assay_runs')->where('id', $incomplete['run_id'])->update(['content_incomplete' => true]);

    expect(fn () => resolve(DatasetManager::class)->add($dataset['id'], $incomplete['run_id']))
        ->toThrow(ConflictHttpException::class)
        ->and(DB::table('assay_dataset_items')->count())->toBe(2);
});

it('resolves browser dataset retention choices and preserves explicit no-expiry', function (): void {
    $owner = createPr7User('Dataset Form Owner', UserRole::Owner);

    $defaultResponse = $this->actingAs($owner)->post(route('assay.datasets.create'), [
        'name' => 'Browser default retention',
        'retention_mode' => 'default',
        'retention_days' => '',
    ])->assertRedirect();
    config()->set('assay.retention.dataset_days', 500);
    $configuredResponse = $this->actingAs($owner)->post(route('assay.datasets.create'), [
        'name' => 'Browser configured default',
        'retention_mode' => 'default',
        'retention_days' => '',
    ])->assertRedirect();
    $customResponse = $this->actingAs($owner)->post(route('assay.datasets.create'), [
        'name' => 'Browser custom retention',
        'retention_mode' => 'custom',
        'retention_days' => '1000',
    ])->assertRedirect();
    $noneResponse = $this->actingAs($owner)->post(route('assay.datasets.create'), [
        'name' => 'Browser no expiry',
        'retention_mode' => 'no_expiry',
        'retention_days' => '',
    ])->assertRedirect();
    $jsonResponse = $this->actingAs($owner)->postJson(route('assay.datasets.create'), [
        'name' => 'JSON no expiry',
        'retention_days' => null,
    ])->assertCreated();

    $this->actingAs($owner)->post(route('assay.datasets.create'), [
        'name' => 'Contradictory retention',
        'retention_mode' => 'no_expiry',
        'retention_days' => '30',
    ])->assertSessionHasErrors('retention_days');
    $this->actingAs($owner)->post(route('assay.datasets.create'), [
        'name' => 'Missing custom retention',
        'retention_mode' => 'custom',
        'retention_days' => '',
    ])->assertSessionHasErrors('retention_days');
    $this->actingAs($owner)->post(route('assay.datasets.create'), [
        'name' => 'Invalid custom retention',
        'retention_mode' => 'custom',
        'retention_days' => '0',
    ])->assertSessionHasErrors('retention_days');

    expect(DB::table('assay_datasets')->where('name', 'Browser default retention')->value('retention_days'))->toBe(365)
        ->and(DB::table('assay_datasets')->where('name', 'Browser configured default')->value('retention_days'))->toBe(500)
        ->and(DB::table('assay_datasets')->where('name', 'Browser custom retention')->value('retention_days'))->toBe(1000)
        ->and(DB::table('assay_datasets')->where('name', 'Browser no expiry')->value('retention_days'))->toBeNull()
        ->and($jsonResponse->json('retention_days'))->toBeNull()
        ->and(DB::table('assay_datasets')->count())->toBe(5);

    foreach ([$defaultResponse, $configuredResponse, $customResponse, $noneResponse] as $response) {
        $response->assertRedirect();
    }
});

it('accepts positive dataset retention independently of usage retention and validates malformed choices', function (): void {
    $manager = resolve(DatasetManager::class);

    expect($manager->create('Above usage retention', 1000)['retention_days'])->toBe(1000)
        ->and($manager->create('Explicit no expiry', null)['retention_days'])->toBeNull();

    config()->set('assay.retention.dataset_days', 500);
    expect($manager->create('Configured above usage')['retention_days'])->toBe(500);

    config()->set('assay.retention.dataset_days', null);
    expect($manager->create('Configured no expiry')['retention_days'])->toBeNull();

    config()->set('assay.retention.dataset_days', 0);
    expect(fn () => $manager->create('Invalid configured default'))->toThrow(RuntimeException::class)
        ->and(fn () => $manager->create('Invalid custom retention', 0))->toThrow(RuntimeException::class)
        ->and(DB::table('assay_datasets')->count())->toBe(4);
});

it('keeps the service and browser dataset index complete beyond one hundred rows', function (): void {
    $owner = createPr7User('Dataset Completeness Owner', UserRole::Owner);
    $manager = resolve(DatasetManager::class);

    for ($index = 0; $index < 105; $index++) {
        $manager->create(sprintf('Complete Dataset %03d', $index));
    }

    $datasets = $manager->datasets();

    expect($datasets)->toHaveCount(105)
        ->and(array_column($datasets, 'name'))->toBe(array_map(
            static fn (int $index): string => sprintf('Complete Dataset %03d', $index),
            range(0, 104),
        ));

    $this->actingAs($owner)->get(route('assay.datasets.index'))
        ->assertOk()
        ->assertSee('Complete Dataset 000')
        ->assertSee('Complete Dataset 104');
});

it('exports deterministic live JSONL without storing an artifact and binds fresh downloads to the requesting principal', function (): void {
    $owner = createPr7User('Export Owner', UserRole::Owner);
    $other = createPr7User('Export Other Admin', UserRole::Admin);
    $source = ingestPr7Run('export-run', 'export-subject');
    resolve(RunCuration::class)->flag($source['run_id'], ['exported'], 'pass', 'EXPORT-NOTE-CANARY');
    $dataset = resolve(DatasetManager::class)->create('Export set');
    resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);

    $requested = $this->actingAs($owner)->postJson(route('assay.datasets.exports.request', $dataset['id']))
        ->assertCreated()->json();
    $first = $this->actingAs($owner)->get($requested['download_url'])->assertOk()
        ->assertHeader('content-type', 'application/x-ndjson; charset=UTF-8');
    $second = $this->actingAs($owner)->get($requested['download_url'])->assertOk();
    $line = json_decode(trim((string) $first->getContent()), true, flags: JSON_THROW_ON_ERROR);

    expect($first->getContent())->toBe($second->getContent())
        ->and(substr_count(trim((string) $first->getContent()), "\n"))->toBe(0)
        ->and($line['schema'])->toBe('assay.dataset-item.v1')
        ->and($line['note'])->toBe('EXPORT-NOTE-CANARY')
        ->and(DB::table('assay_export_requests')->count())->toBe(1)
        ->and(DB::getSchemaBuilder()->hasTable('assay_exports'))->toBeFalse();

    $this->actingAs($other)->get($requested['download_url'])->assertForbidden();
    DB::table('assay_export_requests')->where('id', $requested['id'])->update(['requested_at' => now()->subMinutes(16)]);
    $this->actingAs($owner)->get($requested['download_url'])->assertGone();

    $fresh = $this->actingAs($other)->postJson(route('assay.datasets.exports.request', $dataset['id']))
        ->assertCreated()->json();
    ContentAccessOverride::query()->create([
        'actor_id' => (string) $other->getKey(),
        'access' => ContentAccess::Denied,
        'set_by_actor_id' => (string) $owner->getKey(),
        'set_at' => now(),
    ]);
    $this->actingAs($other)->get($fresh['download_url'])->assertForbidden();
});

it('applies default custom and no-expiry dataset retention without changing usage totals', function (): void {
    $defaultSource = ingestPr7Run('retention-default', 'retention-a');
    $customSource = ingestPr7Run('retention-custom', 'retention-b', 'TWO');
    $noneSource = ingestPr7Run('retention-none', 'retention-c', 'THREE');
    $default = resolve(DatasetManager::class)->create('Default');
    $custom = resolve(DatasetManager::class)->create('Custom', 1);
    $none = resolve(DatasetManager::class)->create('Forever', null);
    $defaultItem = resolve(DatasetManager::class)->add($default['id'], $defaultSource['run_id']);
    $customItem = resolve(DatasetManager::class)->add($custom['id'], $customSource['run_id']);
    $noneItem = resolve(DatasetManager::class)->add($none['id'], $noneSource['run_id']);
    $asOf = CarbonImmutable::parse('2026-10-01T12:00:00+00:00');
    DB::table('assay_dataset_items')->where('id', $defaultItem['id'])->update(['expires_at' => $asOf->format('Y-m-d H:i:s.uP')]);
    DB::table('assay_dataset_items')->where('id', $customItem['id'])->update(['expires_at' => $asOf->subMicrosecond()->format('Y-m-d H:i:s.uP')]);
    DB::table('assay_dataset_items')->where('id', $noneItem['id'])->update(['expires_at' => null]);
    $usage = DB::table('assay_usage_metrics')->sum('value');

    $result = resolve(RetentionPruner::class)->prune($asOf, 1);

    expect($default['retention_days'])->toBe(365)
        ->and($custom['retention_days'])->toBe(1)
        ->and($none['retention_days'])->toBeNull()
        ->and($result['dataset_items_deleted'])->toBe(2)
        ->and(DB::table('assay_dataset_items')->pluck('id')->all())->toBe([$noneItem['id']])
        ->and(DB::table('assay_usage_metrics')->sum('value'))->toEqual($usage);
});

it('pins source ancestry while a delayed default-retention item is live', function (): void {
    $owner = createPr7User('Pinned Default Retention Owner', UserRole::Owner);
    $principal = ActingPrincipal::local('web', $owner);
    $source = ingestPr7Run('pinned-default-retention', null, 'PINNED-DEFAULT');
    $runOccurredAt = CarbonImmutable::now()->subDays(100);
    agePr7Run($source['run_id'], $runOccurredAt);
    $dataset = resolve(DatasetManager::class)->create('Pinned default retention');
    $item = resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);
    $request = resolve(DatasetExporter::class)->request($dataset['id'], $principal);
    ingestPr7LateSubject('pinned-default-retention', 'pinned-default-subject');
    $asOf = CarbonImmutable::parse($item['added_at'])->addDays(300);

    expect(config('assay.retention.dataset_days'))->toBe(365)
        ->and(config('assay.retention.usage_days'))->toBe(395);

    $pruned = resolve(RetentionPruner::class)->prune($asOf);

    expect($pruned['usage_runs_deleted'])->toBe(0)
        ->and($pruned['dataset_items_deleted'])->toBe(0)
        ->and(DB::table('assay_runs')->where('id', $source['run_id'])->exists())->toBeTrue()
        ->and(DB::table('assay_dataset_items')->where('id', $item['id'])->value('source_run_id'))->toBe($source['run_id']);

    $erasure = resolve(SubjectErasure::class)->erase(
        $source['app_id'],
        'pinned-default-subject',
        CarbonImmutable::parse('2030-01-01T00:00:00+00:00'),
    );

    expect($erasure['dataset_items_deleted'])->toBe(1)
        ->and(resolve(DatasetExporter::class)->render($request['id'], $principal))->toBe('');
});

it('pins source ancestry for equal retention with a one-day curation delay', function (): void {
    $owner = createPr7User('Pinned Equal Retention Owner', UserRole::Owner);
    $principal = ActingPrincipal::local('web', $owner);
    $source = ingestPr7Run('pinned-equal-retention', null, 'PINNED-EQUAL');
    $runOccurredAt = CarbonImmutable::now()->subDay();
    agePr7Run($source['run_id'], $runOccurredAt);
    $dataset = resolve(DatasetManager::class)->create('Pinned equal retention', 395);
    $item = resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);
    $request = resolve(DatasetExporter::class)->request($dataset['id'], $principal);
    ingestPr7LateSubject('pinned-equal-retention', 'pinned-equal-subject');
    $asOf = $runOccurredAt->addDays(395)->addMinute();

    expect($dataset['retention_days'])->toBe(395)
        ->and(config('assay.retention.usage_days'))->toBe(395);

    $pruned = resolve(RetentionPruner::class)->prune($asOf);

    expect($asOf->lessThan(CarbonImmutable::parse($item['expires_at'])))->toBeTrue()
        ->and($pruned['usage_runs_deleted'])->toBe(0)
        ->and($pruned['dataset_items_deleted'])->toBe(0)
        ->and(DB::table('assay_runs')->where('id', $source['run_id'])->exists())->toBeTrue();

    $erasure = resolve(SubjectErasure::class)->erase(
        $source['app_id'],
        'pinned-equal-subject',
        CarbonImmutable::parse('2030-01-01T00:00:00+00:00'),
    );

    expect($erasure['dataset_items_deleted'])->toBe(1)
        ->and(resolve(DatasetExporter::class)->render($request['id'], $principal))->toBe('');
});

it('releases expired source ancestry during the same prune invocation', function (): void {
    $middle = ingestPr7Run('released-middle', null, 'RELEASE-MIDDLE', parentInvocation: 'released-root');
    $source = ingestPr7Run('released-source', null, 'RELEASE-SOURCE', parentInvocation: 'released-middle');
    $rootId = (string) DB::table('assay_runs')->where('invocation_id', 'released-root')->value('id');
    $occurredAt = CarbonImmutable::now()->subDays(500);

    foreach ([$rootId, $middle['run_id'], $source['run_id']] as $runId) {
        agePr7Run($runId, $occurredAt);
    }

    $dataset = resolve(DatasetManager::class)->create('Released ancestry', 1);
    $item = resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);
    $asOf = CarbonImmutable::now();
    DB::table('assay_dataset_items')->where('id', $item['id'])
        ->update(['expires_at' => $asOf->format('Y-m-d H:i:s.uP')]);

    $pruned = resolve(RetentionPruner::class)->prune($asOf);

    expect($pruned['dataset_items_deleted'])->toBe(1)
        ->and($pruned['usage_runs_deleted'])->toBe(3)
        ->and(DB::table('assay_dataset_items')->where('id', $item['id'])->exists())->toBeFalse()
        ->and(DB::table('assay_runs')->whereIn('id', [$rootId, $middle['run_id'], $source['run_id']])->count())->toBe(0);
});

it('pins no-expiry source ancestry while pruning its ordinary content', function (): void {
    $middle = ingestPr7Run('no-expiry-pinned-middle', null, 'NO-EXPIRY-MIDDLE', parentInvocation: 'no-expiry-pinned-root');
    $source = ingestPr7Run('no-expiry-pinned-source', null, 'NO-EXPIRY-PIN', parentInvocation: 'no-expiry-pinned-middle');
    $rootId = (string) DB::table('assay_runs')->where('invocation_id', 'no-expiry-pinned-root')->value('id');

    foreach ([$rootId, $middle['run_id'], $source['run_id']] as $runId) {
        agePr7Run($runId, CarbonImmutable::now()->subDays(500));
    }

    $dataset = resolve(DatasetManager::class)->create('No-expiry pin', null);
    $item = resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);

    $pruned = resolve(RetentionPruner::class)->prune(CarbonImmutable::now());

    expect($pruned['content_rows_deleted'])->toBeGreaterThan(0)
        ->and($pruned['usage_runs_deleted'])->toBe(0)
        ->and(DB::table('assay_dataset_items')->where('id', $item['id'])->value('expires_at'))->toBeNull()
        ->and(DB::table('assay_runs')->whereIn('id', [$rootId, $middle['run_id'], $source['run_id']])->count())->toBe(3)
        ->and(DB::table('assay_record_content')->where('run_id', $source['run_id'])->exists())->toBeFalse()
        ->and(DB::table('assay_messages')->where('run_id', $source['run_id'])->exists())->toBeFalse();
});

it('releases shared ancestry only after the last live item expires', function (): void {
    $root = ingestPr7Run('shared-pin-root', null, 'SHARED-ROOT');
    $first = ingestPr7Run('shared-pin-first', null, 'SHARED-FIRST', parentInvocation: 'shared-pin-root');
    $second = ingestPr7Run('shared-pin-second', null, 'SHARED-SECOND', parentInvocation: 'shared-pin-root');
    $occurredAt = CarbonImmutable::now()->subDays(500);

    foreach ([$root['run_id'], $first['run_id'], $second['run_id']] as $runId) {
        agePr7Run($runId, $occurredAt);
    }

    $firstDataset = resolve(DatasetManager::class)->create('First shared pin', 1);
    $secondDataset = resolve(DatasetManager::class)->create('Second shared pin', 2);
    $firstItem = resolve(DatasetManager::class)->add($firstDataset['id'], $first['run_id']);
    $secondItem = resolve(DatasetManager::class)->add($secondDataset['id'], $second['run_id']);
    $firstAsOf = CarbonImmutable::now();
    $secondAsOf = $firstAsOf->addDay();
    DB::table('assay_dataset_items')->where('id', $firstItem['id'])
        ->update(['expires_at' => $firstAsOf->format('Y-m-d H:i:s.uP')]);
    DB::table('assay_dataset_items')->where('id', $secondItem['id'])
        ->update(['expires_at' => $secondAsOf->format('Y-m-d H:i:s.uP')]);

    $firstPrune = resolve(RetentionPruner::class)->prune($firstAsOf);

    expect($firstPrune['dataset_items_deleted'])->toBe(1)
        ->and($firstPrune['usage_runs_deleted'])->toBe(1)
        ->and(DB::table('assay_runs')->where('id', $first['run_id'])->exists())->toBeFalse()
        ->and(DB::table('assay_runs')->whereIn('id', [$root['run_id'], $second['run_id']])->count())->toBe(2);

    $secondPrune = resolve(RetentionPruner::class)->prune($secondAsOf);

    expect($secondPrune['dataset_items_deleted'])->toBe(1)
        ->and($secondPrune['usage_runs_deleted'])->toBe(2)
        ->and(DB::table('assay_runs')->whereIn('id', [$root['run_id'], $second['run_id']])->count())->toBe(0);
});

it('does not pin ancestry across application boundaries', function (): void {
    $other = ingestPr7Run('cross-app-parent', null, 'CROSS-APP-PARENT', appRef: 'pr7-other-app');
    $source = ingestPr7Run('cross-app-source', null, 'CROSS-APP-SOURCE');
    $occurredAt = CarbonImmutable::now()->subDays(500);
    agePr7Run($other['run_id'], $occurredAt);
    agePr7Run($source['run_id'], $occurredAt);
    DB::table('assay_runs')->where('id', $source['run_id'])->update(['parent_run_id' => $other['run_id']]);
    $dataset = resolve(DatasetManager::class)->create('App-isolated ancestry');
    resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);

    $pruned = resolve(RetentionPruner::class)->prune(CarbonImmutable::now());

    expect($pruned['usage_runs_deleted'])->toBe(1)
        ->and(DB::table('assay_runs')->where('id', $other['run_id'])->exists())->toBeFalse()
        ->and(DB::table('assay_runs')->where('id', $source['run_id'])->exists())->toBeTrue()
        ->and(DB::table('assay_runs')->where('id', $source['run_id'])->value('parent_run_id'))->toBeNull();
});

it('restricts direct deletion of a dataset item source run', function (): void {
    $source = ingestPr7Run('restricted-source-delete', null, 'RESTRICTED-SOURCE');
    $dataset = resolve(DatasetManager::class)->create('Restricted source');
    $item = resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);
    $foreignKey = DB::selectOne(<<<'SQL'
        SELECT confdeltype, pg_get_constraintdef(oid) AS definition
        FROM pg_constraint
        WHERE conrelid = 'assay_dataset_items'::regclass
            AND contype = 'f'
            AND pg_get_constraintdef(oid) LIKE 'FOREIGN KEY (source_run_id)%'
        SQL);

    expect($foreignKey)->not->toBeNull();
    expect((string) $foreignKey->confdeltype)->toBe('r')
        ->and((string) $foreignKey->definition)->toContain('FOREIGN KEY (source_run_id)', 'REFERENCES assay_runs(id)', 'ON DELETE RESTRICT')
        ->and(fn () => DB::transaction(
            static fn (): int => DB::table('assay_runs')->where('id', $source['run_id'])->delete(),
        ))->toThrow(QueryException::class)
        ->and(DB::table('assay_dataset_items')->where('id', $item['id'])->value('source_run_id'))->toBe($source['run_id']);
});

it('removes flags and dataset items through erasure while preserving usage and rendering later downloads live', function (): void {
    $owner = createPr7User('Erasure Export Owner', UserRole::Owner);
    $source = ingestPr7Run('erasure-dataset-run', 'erasure-dataset-subject');
    resolve(RunCuration::class)->flag($source['run_id'], ['ERASURE-LABEL-CANARY'], 'fail', 'ERASURE-NOTE-CANARY');
    $dataset = resolve(DatasetManager::class)->create('Erasure set');
    resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);
    $request = $this->actingAs($owner)->postJson(route('assay.datasets.exports.request', $dataset['id']))->json();
    $usage = DB::table('assay_usage_metrics')->sum('value');

    $result = resolve(SubjectErasure::class)->erase($source['app_id'], 'erasure-dataset-subject', CarbonImmutable::parse('2027-01-01T00:00:00+00:00'));
    $download = $this->actingAs($owner)->get($request['download_url'])->assertOk();

    expect($result['dataset_items_deleted'])->toBe(1)
        ->and(DB::table('assay_run_flags')->count())->toBe(0)
        ->and(DB::table('assay_dataset_items')->count())->toBe(0)
        ->and($download->getContent())->toBe('')
        ->and(DB::table('assay_usage_metrics')->sum('value'))->toEqual($usage);
});

it('removes inherited-subject dataset snapshots and renders later exports empty', function (): void {
    $owner = createPr7User('Inherited Erasure Owner', UserRole::Owner);
    $subject = 'inherited-dataset-subject';
    $root = ingestPr7Run('inherited-dataset-root', $subject, 'ROOT');
    $child = ingestPr7Run('inherited-dataset-child', null, 'CHILD', parentInvocation: 'inherited-dataset-root');
    $dataset = resolve(DatasetManager::class)->create('Inherited erasure set');
    $item = resolve(DatasetManager::class)->add($dataset['id'], $child['run_id']);
    $identity = resolve(SubjectErasureHasher::class)->active($subject);
    $stored = DB::table('assay_dataset_items')->where('id', $item['id'])->sole();
    $request = $this->actingAs($owner)->postJson(route('assay.datasets.exports.request', $dataset['id']))->json();

    expect($root['app_id'])->toBe($child['app_id'])
        ->and(DB::table('assay_runs')->where('id', $child['run_id'])->value('subject'))->toBeNull()
        ->and($stored->subject_key_version)->toBe($identity['version'])
        ->and($stored->subject_tombstone)->toBe($identity['tombstone']);

    $result = resolve(SubjectErasure::class)->erase(
        $root['app_id'],
        $subject,
        CarbonImmutable::parse('2027-01-01T00:00:00+00:00'),
    );
    $download = $this->actingAs($owner)->get($request['download_url'])->assertOk();

    expect($result['dataset_items_deleted'])->toBe(1)
        ->and(DB::table('assay_dataset_items')->count())->toBe(0)
        ->and($download->getContent())->toBe('');
});

it('keeps the no-prune late-subject control reachable and empties a pre-minted export', function (): void {
    $owner = createPr7User('Late Direct Subject Owner', UserRole::Owner);
    $source = ingestPr7Run('late-direct-subject', null, 'LATE-DIRECT');
    $dataset = resolve(DatasetManager::class)->create('Late direct subject');
    resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);
    $request = resolve(DatasetExporter::class)->request($dataset['id'], ActingPrincipal::local('web', $owner));

    expect(DB::table('assay_dataset_items')->value('subject_tombstone'))->toBeNull();
    ingestPr7LateSubject('late-direct-subject', 'late-direct-owner');

    $result = resolve(SubjectErasure::class)->erase(
        $source['app_id'],
        'late-direct-owner',
        CarbonImmutable::parse('2027-01-01T00:00:00+00:00'),
    );

    expect($result['dataset_items_deleted'])->toBe(1)
        ->and(DB::table('assay_dataset_items')->count())->toBe(0)
        ->and(resolve(DatasetExporter::class)->render($request['id'], ActingPrincipal::local('web', $owner)))->toBe('');
});

it('removes a snapshot when its subject attaches through a parent stub after curation', function (): void {
    $owner = createPr7User('Late Parent Subject Owner', UserRole::Owner);
    $source = ingestPr7Run('late-parent-child', null, 'LATE-PARENT', parentInvocation: 'late-parent-root');
    $dataset = resolve(DatasetManager::class)->create('Late parent subject');
    resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);
    $request = resolve(DatasetExporter::class)->request($dataset['id'], ActingPrincipal::local('web', $owner));

    expect(DB::table('assay_dataset_items')->value('subject_tombstone'))->toBeNull();
    ingestPr7LateSubject('late-parent-root', 'late-parent-owner');

    $result = resolve(SubjectErasure::class)->erase(
        $source['app_id'],
        'late-parent-owner',
        CarbonImmutable::parse('2027-01-01T00:00:00+00:00'),
    );

    expect($result['dataset_items_deleted'])->toBe(1)
        ->and(DB::table('assay_dataset_items')->count())->toBe(0)
        ->and(resolve(DatasetExporter::class)->render($request['id'], ActingPrincipal::local('web', $owner)))->toBe('');
});

it('keeps a late subject two levels up reachable without a usage prune', function (): void {
    $owner = createPr7User('Late Grandparent Subject Owner', UserRole::Owner);
    ingestPr7Run('late-grandparent-middle', null, 'LATE-MIDDLE', parentInvocation: 'late-grandparent-root');
    $source = ingestPr7Run('late-grandparent-child', null, 'LATE-GRANDPARENT', parentInvocation: 'late-grandparent-middle');
    $dataset = resolve(DatasetManager::class)->create('Late grandparent subject');
    $item = resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);
    $request = resolve(DatasetExporter::class)->request($dataset['id'], ActingPrincipal::local('web', $owner));

    expect(DB::table('assay_dataset_items')->where('id', $item['id'])->value('subject_tombstone'))->toBeNull();
    ingestPr7LateSubject('late-grandparent-root', 'late-grandparent-owner');

    $result = resolve(SubjectErasure::class)->erase(
        $source['app_id'],
        'late-grandparent-owner',
        CarbonImmutable::parse('2027-01-01T00:00:00+00:00'),
    );

    expect($result['dataset_items_deleted'])->toBe(1)
        ->and(DB::table('assay_dataset_items')->where('id', $item['id'])->exists())->toBeFalse()
        ->and(resolve(DatasetExporter::class)->render($request['id'], ActingPrincipal::local('web', $owner)))->toBe('');
});

it('uses current ancestry while the source exists and the stored subject only after it is gone', function (): void {
    $owner = createPr7User('Subject Authority Owner', UserRole::Owner);
    $principal = ActingPrincipal::local('web', $owner);
    $source = ingestPr7Run('subject-authority', 'stored-subject', 'AUTHORITY');
    $dataset = resolve(DatasetManager::class)->create('Subject authority');
    resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);
    $request = resolve(DatasetExporter::class)->request($dataset['id'], $principal);
    ingestPr7LateSubject('subject-authority', 'current-subject');

    $storedErasure = resolve(SubjectErasure::class)->erase(
        $source['app_id'],
        'stored-subject',
        CarbonImmutable::parse('2027-01-01T00:00:00+00:00'),
    );

    expect($storedErasure['dataset_items_deleted'])->toBe(0)
        ->and(DB::table('assay_dataset_items')->count())->toBe(1)
        ->and(resolve(DatasetExporter::class)->render($request['id'], $principal))->toContain('INSTRUCTIONS-AUTHORITY');

    DB::table('assay_dataset_items')->update(['source_run_id' => null]);
    DB::table('assay_runs')->where('id', $source['run_id'])->delete();

    expect(DB::table('assay_dataset_items')->value('source_run_id'))->toBeNull()
        ->and(resolve(DatasetExporter::class)->render($request['id'], $principal))->toBe('');

    $fallbackErasure = resolve(SubjectErasure::class)->erase(
        $source['app_id'],
        'stored-subject',
        CarbonImmutable::parse('2027-01-01T00:00:00+00:00'),
    );

    expect($fallbackErasure['dataset_items_deleted'])->toBe(1)
        ->and(DB::table('assay_dataset_items')->count())->toBe(0);
});

it('enforces the run flag foreign key and cascades flags with runs and apps', function (): void {
    $first = ingestPr7Run('flag-fk-run', 'flag-fk-subject');
    $second = ingestPr7Run('flag-fk-app', 'flag-fk-app-subject', 'TWO');
    resolve(RunCuration::class)->flag($first['run_id'], ['run-delete'], null, 'RUN-DELETE-FLAG');
    resolve(RunCuration::class)->flag($second['run_id'], ['app-delete'], null, 'APP-DELETE-FLAG');
    $foreignKey = DB::selectOne(<<<'SQL'
        SELECT confdeltype, pg_get_constraintdef(oid) AS definition
        FROM pg_constraint
        WHERE conrelid = 'assay_run_flags'::regclass AND contype = 'f'
        SQL);

    expect($foreignKey)->not->toBeNull();
    expect((string) $foreignKey->confdeltype)->toBe('c')
        ->and((string) $foreignKey->definition)->toContain('FOREIGN KEY (run_id)', 'REFERENCES assay_runs(id)', 'ON DELETE CASCADE');

    DB::table('assay_runs')->where('id', $first['run_id'])->delete();

    expect(DB::table('assay_run_flags')->where('run_id', $first['run_id'])->exists())->toBeFalse()
        ->and(DB::table('assay_run_flags')->where('run_id', $second['run_id'])->exists())->toBeTrue()
        ->and(DB::table('assay_run_flags as flag')->leftJoin('assay_runs as run', 'run.id', '=', 'flag.run_id')->whereNull('run.id')->count())->toBe(0);

    DB::table('assay_apps')->where('id', $second['app_id'])->delete();

    expect(DB::table('assay_runs')->count())->toBe(0)
        ->and(DB::table('assay_run_flags')->count())->toBe(0)
        ->and(DB::table('assay_run_flags as flag')->join('assay_runs as run', 'run.id', '=', 'flag.run_id')->count())->toBe(0)
        ->and(DB::table('assay_run_flags as flag')->leftJoin('assay_runs as run', 'run.id', '=', 'flag.run_id')->whereNull('run.id')->count())->toBe(0);
});

it('gates every PR7 route through central content policy and keeps usage projections content-free', function (): void {
    $owner = createPr7User('PR7 Matrix Owner', UserRole::Owner);
    $admin = createPr7User('PR7 Matrix Admin', UserRole::Admin);
    $member = createPr7User('PR7 Matrix Member', UserRole::Member);
    $narrowed = createPr7User('PR7 Matrix Narrowed', UserRole::Admin);
    ContentAccessOverride::query()->create([
        'actor_id' => (string) $narrowed->getKey(), 'access' => ContentAccess::Denied,
        'set_by_actor_id' => (string) $owner->getKey(), 'set_at' => now(),
    ]);
    $source = ingestPr7Run('matrix-run', 'matrix-subject');
    resolve(RunCuration::class)->flag($source['run_id'], ['MATRIX-LABEL-CANARY'], 'pass', 'MATRIX-NOTE-CANARY');
    $dataset = resolve(DatasetManager::class)->create('Matrix dataset');
    $export = $this->actingAs($owner)->postJson(route('assay.datasets.exports.request', $dataset['id']))->json();

    $getRoutes = [
        route('assay.runs.curate', $source['run_id']), route('assay.datasets.index'),
        route('assay.datasets.show', $dataset['id']),
    ];

    foreach ([$owner, $admin] as $actor) {
        foreach ($getRoutes as $url) {
            $this->actingAs($actor)->get($url)->assertOk();
        }
    }

    foreach ([$member, $narrowed] as $actor) {
        foreach ($getRoutes as $url) {
            $this->actingAs($actor)->get($url)->assertForbidden();
        }

        $this->actingAs($actor)->putJson(route('assay.runs.flag', $source['run_id']), ['labels' => []])->assertForbidden();
        $this->actingAs($actor)->postJson(route('assay.datasets.create'), ['name' => 'Denied'])->assertForbidden();
        $this->actingAs($actor)->postJson(route('assay.datasets.items.add', $dataset['id']), ['run_id' => $source['run_id']])->assertForbidden();
        $this->actingAs($actor)->postJson(route('assay.datasets.exports.request', $dataset['id']))->assertForbidden();
    }

    $this->actingAs($owner)->get($export['download_url'])->assertOk();
    $this->actingAs($admin)->get($export['download_url'])->assertForbidden();
    $adminExport = $this->actingAs($admin)->postJson(route('assay.datasets.exports.request', $dataset['id']))->json();
    $this->actingAs($admin)->get($adminExport['download_url'])->assertOk();

    $ingest = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'pr7-denied-app',
    ]);

    foreach ($getRoutes as $url) {
        $this->withHeader('Authorization', $ingest->bearerHeader())->get($url)->assertForbidden();
    }

    $this->withHeader('Authorization', $ingest->bearerHeader())
        ->putJson(route('assay.runs.flag', $source['run_id']), ['labels' => []])->assertForbidden();
    $this->withHeader('Authorization', $ingest->bearerHeader())
        ->postJson(route('assay.datasets.create'), ['name' => 'Denied'])->assertForbidden();
    $this->withHeader('Authorization', $ingest->bearerHeader())
        ->postJson(route('assay.datasets.items.add', $dataset['id']), ['run_id' => $source['run_id']])->assertForbidden();
    $this->withHeader('Authorization', $ingest->bearerHeader())
        ->postJson(route('assay.datasets.exports.request', $dataset['id']))->assertForbidden();

    $delegatedActor = DelegatedActor::query()->create([
        'identity_hash' => DelegatedActor::identityHash('pr7-issuer', 'pr7-operator'),
        'issuer' => 'pr7-issuer',
        'subject' => 'pr7-operator',
        'last_handoff_display_name' => 'PR7 Operator',
        'last_handoff_role' => ConsoleRole::Admin,
    ]);
    $delegated = ActingPrincipal::delegatedRequest(
        $delegatedActor,
        new DelegatedClaims('PR7 Operator', ConsoleRole::Admin, 'PR7 Agency'),
    );
    expect(resolve(AssayAccessPolicy::class)->decide($delegated, null)->content)->toBeTrue();
    $delegatedExport = resolve(DatasetExporter::class)->request($dataset['id'], $delegated);
    expect(resolve(DatasetExporter::class)->render($delegatedExport['id'], $delegated))->toBe('');

    $usage = $this->withHeader('Authorization', '')->actingAs($member)->getJson(route('assay.dashboard.top-runs'))->assertOk();
    expect((string) $usage->getContent())->not->toContain('MATRIX-LABEL-CANARY', 'MATRIX-NOTE-CANARY');

    $expectedNames = [
        'assay.runs.curate', 'assay.runs.flag', 'assay.datasets.index', 'assay.datasets.create',
        'assay.datasets.show', 'assay.datasets.items.add', 'assay.datasets.exports.request', 'assay.exports.download',
    ];

    foreach ($expectedNames as $name) {
        expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('assay.access:content');
    }
});

it('renders structural curation disclosures from test-created data and keeps canaries out of validation logs and queue residue', function (): void {
    $owner = createPr7User('PR7 Surface Owner', UserRole::Owner);
    $source = ingestPr7Run('surface-run', 'surface-subject');
    $dataset = resolve(DatasetManager::class)->create('SURFACE-DATASET-CANARY');
    $logs = [];
    Log::listen(static function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event->message.json_encode($event->context, JSON_THROW_ON_ERROR);
    });

    $this->actingAs($owner)->get(route('assay.runs.curate', $source['run_id']))
        ->assertOk()->assertSee('data-testid="run-curation"', false)->assertSee('data-testid="run-curation-flag"', false);
    $this->actingAs($owner)->get(route('assay.datasets.index'))
        ->assertOk()->assertSee('data-testid="datasets-retention-disclosure"', false)->assertSee('SURFACE-DATASET-CANARY');
    $this->actingAs($owner)->get(route('assay.datasets.show', $dataset['id']))
        ->assertOk()->assertSee('data-testid="dataset-retention"', false)->assertSee('365 days from item addition');
    $this->actingAs($owner)->get(route('assay.risk'))
        ->assertOk()->assertSee('data-testid="risk-dataset-curation"', false);
    $secret = 'VALIDATION-PRIVACY-CANARY';
    $response = $this->actingAs($owner)->putJson(route('assay.runs.flag', $source['run_id']), [
        'labels' => [str_repeat($secret, 10)],
    ])->assertUnprocessable();

    expect((string) $response->getContent())->not->toContain($secret)
        ->and(implode("\n", $logs))->not->toContain($secret)
        ->and(json_encode(DB::table('jobs')->get(), JSON_THROW_ON_ERROR))->not->toContain($secret)
        ->and(json_encode(DB::table('failed_jobs')->get(), JSON_THROW_ON_ERROR))->not->toContain($secret);
});
