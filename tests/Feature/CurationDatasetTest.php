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
    string $subject,
    string $suffix = 'ONE',
    bool $omissionsPresent = false,
): array {
    $message = ['role' => 'user', 'text' => "INPUT-{$suffix}"];
    $hash = hash('sha256', json_encode($message, JSON_THROW_ON_ERROR));
    $records = [
        EnvelopeFactory::record([
            'invocation_id' => $invocation,
            'subject' => $subject,
            'capture' => 'full',
            'sampled' => true,
            'agent' => 'App\\Ai\\DatasetAgent',
            'model' => ['provider' => 'provider-'.$suffix, 'requested' => 'requested-'.$suffix],
            'content' => [
                'instructions' => "INSTRUCTIONS-{$suffix}",
                'tools' => [['name' => 'lookup', 'description' => "TOOL-{$suffix}", 'parameters' => ['type' => 'object']]],
            ],
        ]),
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
    resolve(UsageIngestProcessor::class)->process('pr7-app', '2026-10-01T12:10:00.000000+00:00', [
        ...$envelope->toArray(),
        'records' => array_map(static function ($record): array {
            $data = $record->toArray();

            if ($record->content !== null) {
                $data['content'] = $record->content->toJson();
            }

            return $data;
        }, $records),
    ]);

    return [
        'run_id' => (string) DB::table('assay_runs')->where('invocation_id', $invocation)->value('id'),
        'app_id' => (string) DB::table('assay_apps')->where('app_ref', 'pr7-app')->value('id'),
    ];
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

it('creates a dataset with custom retention from a browser form', function (): void {
    $owner = createPr7User('Dataset Form Owner', UserRole::Owner);

    $response = $this->actingAs($owner)->post(route('assay.datasets.create'), [
        'name' => 'Browser custom retention',
        'retention_days' => '30',
    ]);
    $response->assertRedirect();
    $dataset = DB::table('assay_datasets')->where('name', 'Browser custom retention');

    $response->assertRedirect(route('assay.datasets.show', (string) $dataset->value('id')));
    expect($dataset->value('retention_days'))->toBe(30);
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

it('applies default custom and no-expiry dataset retention in bounded batches without changing usage totals', function (): void {
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

it('removes flags and dataset items through erasure while preserving usage and rendering later downloads live', function (): void {
    $owner = createPr7User('Erasure Export Owner', UserRole::Owner);
    $source = ingestPr7Run('erasure-dataset-run', 'erasure-dataset-subject');
    resolve(RunCuration::class)->flag($source['run_id'], ['ERASURE-LABEL-CANARY'], 'fail', 'ERASURE-NOTE-CANARY');
    $dataset = resolve(DatasetManager::class)->create('Erasure set', null);
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

    // Credential-held usage/content abilities and person-bound caps remain explicitly deferred to PR7b.
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
    $dataset = resolve(DatasetManager::class)->create('SURFACE-DATASET-CANARY', null);
    $logs = [];
    Log::listen(static function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event->message.json_encode($event->context, JSON_THROW_ON_ERROR);
    });

    $this->actingAs($owner)->get(route('assay.runs.curate', $source['run_id']))
        ->assertOk()->assertSee('data-testid="run-curation"', false)->assertSee('data-testid="run-curation-flag"', false);
    $this->actingAs($owner)->get(route('assay.datasets.index'))
        ->assertOk()->assertSee('data-testid="datasets-retention-disclosure"', false)->assertSee('SURFACE-DATASET-CANARY');
    $this->actingAs($owner)->get(route('assay.datasets.show', $dataset['id']))
        ->assertOk()->assertSee('data-testid="dataset-retention"', false)->assertSee('No expiry');
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
