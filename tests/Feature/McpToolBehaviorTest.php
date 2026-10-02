<?php

declare(strict_types=1);

use App\Authorization\AssayCredentialAbility;
use App\Enums\ContentAccess;
use App\Mcp\Tools\DatasetAdd;
use App\Mcp\Tools\DatasetCreate;
use App\Mcp\Tools\DatasetExport;
use App\Mcp\Tools\DatasetList;
use App\Mcp\Tools\DeleteSubject;
use App\Mcp\Tools\FlagRun;
use App\Mcp\Tools\LabelRun;
use App\Mcp\Tools\ReliabilitySummary;
use App\Mcp\Tools\RunContent;
use App\Mcp\Tools\RunTree;
use App\Mcp\Tools\SearchRuns;
use App\Mcp\Tools\TopRuns;
use App\Mcp\Tools\UsageSummary;
use App\Models\ContentAccessOverride;
use App\Services\DatasetManager;
use App\Services\UsageIngestProcessor;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\Mcp\CanonicalToolArguments;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseCallTool;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseConfirmationRefused;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseConfirmationStore;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\MintedTestCredential;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Tests\Support\EnvelopeFactory;

uses(WithCredentials::class);

beforeEach(function (): void {
    config()->set('built-for-cloud.console.audience', 'https://assay.test');
    config()->set('built-for-cloud.mcp.two_phase.cache_store', 'database');
    config()->set('assay.erasure.journal_disk', 'erasure-journal');
    config()->set('assay.erasure.journal_prefix', 'journal');
    config()->set('assay.erasure.active_key_version', 'v1');
    config()->set('assay.erasure.keys', ['v1' => 'MCP-ERASURE-KEY-CANARY-AT-LEAST-32-BYTES']);
    Storage::fake('erasure-journal');
});

/** @param array<string, mixed> $arguments */
function mcp8Call(string $path, MintedTestCredential $credential, string $tool, array $arguments = [], int $id = 1): TestResponse
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ], ['Authorization' => $credential->bearerHeader()]);
}

function mcp8List(string $path, MintedTestCredential $credential): TestResponse
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
    ], ['Authorization' => $credential->bearerHeader()]);
}

/** @return list<string> */
function mcp8Names(TestResponse $response): array
{
    $response->assertOk();

    return array_column($response->json('result.tools'), 'name');
}

/** @return array<string, mixed> */
function mcp8Result(TestResponse $response): array
{
    $response->assertOk()->assertJsonPath('result.isError', false);
    $text = $response->json('result.content.0.text');

    expect($text)->toBeString();

    /** @var array<string, mixed> $result */
    $result = json_decode($text, true, flags: JSON_THROW_ON_ERROR);

    return $result;
}

/** @return array{run_id: string, app_id: string} */
function seedMcp8Run(string $app, string $invocation, string $subject, string $canary): array
{
    $message = ['role' => 'user', 'text' => 'MESSAGE-'.$canary];
    $hash = hash('sha256', json_encode($message, JSON_THROW_ON_ERROR));
    $records = [
        EnvelopeFactory::record([
            'invocation_id' => $invocation,
            'capture' => 'full',
            'sampled' => true,
            'subject' => $subject,
            'agent' => 'App\\Ai\\McpAgent',
            'model' => ['provider' => 'mcp-provider', 'requested' => 'mcp-requested'],
            'content' => [
                'instructions' => 'INSTRUCTIONS-'.$canary,
                'tools' => [['name' => 'lookup', 'description' => 'Canary lookup tool', 'parameters' => ['type' => 'object']]],
            ],
        ]),
        EnvelopeFactory::record([
            'type' => 'step.start',
            'invocation_id' => $invocation,
            'step' => 0,
            'capture' => 'full',
            'content' => ['message_hashes' => [$hash], 'new_messages' => [$hash => $message]],
        ]),
        EnvelopeFactory::record([
            'type' => 'step.end',
            'invocation_id' => $invocation,
            'step' => 0,
            'capture' => 'full',
            'usage' => ['input_tokens' => 7],
            'duration_ms' => 4.0,
            'model' => ['provider' => 'mcp-provider', 'requested' => 'mcp-requested', 'responded' => 'mcp-responded'],
            'content' => [
                'output_text' => 'OUTPUT-'.$canary,
                'structured_output' => ['answer' => $canary],
                'tool_calls' => [['id' => 'call-1', 'name' => 'lookup', 'arguments' => ['secret' => $canary]]],
            ],
        ]),
        EnvelopeFactory::record([
            'type' => 'run.end',
            'invocation_id' => $invocation,
            'outcome' => 'completed',
            'capture' => 'full',
            'usage' => ['input_tokens' => 7],
            'model' => ['provider' => 'mcp-provider', 'requested' => 'mcp-requested', 'responded' => 'mcp-responded'],
        ]),
    ];
    $envelope = EnvelopeFactory::envelope($records);

    resolve(UsageIngestProcessor::class)->process($app, '2026-10-01T12:10:00.000000+00:00', [
        ...$envelope->toArray(),
        'records' => array_map(static function ($record): array {
            $data = $record->toArray();

            if ($record->content !== null) {
                $data['content'] = $record->content->toJson();
            }

            return $data;
        }, $records),
    ]);

    $appId = (string) DB::table('assay_apps')->where('app_ref', $app)->value('id');

    return [
        'run_id' => (string) DB::table('assay_runs')->where('app_id', $appId)->where('invocation_id', $invocation)->value('id'),
        'app_id' => $appId,
    ];
}

it('executes the complete tool surface with bounded app isolation and metadata canaries', function (): void {
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'mcp-tool-behavior',
        'abilities' => [AssayCredentialAbility::Usage->value, AssayCredentialAbility::Content->value],
    ]);
    $first = seedMcp8Run('mcp-app-a', 'mcp-run-a', 'mcp-subject-a', 'RAW-CONTENT-CANARY-A');
    seedMcp8Run('mcp-app-b', 'mcp-run-b', 'mcp-subject-b', 'RAW-CONTENT-CANARY-B');

    $usage = mcp8Result(mcp8Call('/mcp/destructive', $credential, 'usage_summary', [
        'metric' => 'input_tokens', 'group_by' => 'app',
        'from' => '2026-01-01T00:00:00+00:00', 'to' => '2026-12-31T23:59:59+00:00',
    ]));
    expect($usage['unit'])->toBe('tokens')
        ->and($usage['observed_successful_usage'])->toHaveCount(2)
        ->and($usage['observed_successful_usage'][0]['usage'])->toBe('7');

    $top = mcp8Call('/mcp/destructive', $credential, 'top_runs', ['metric' => 'input_tokens']);
    $reliability = mcp8Call('/mcp/destructive', $credential, 'reliability_summary');
    $tree = mcp8Call('/mcp/destructive', $credential, 'run_tree', ['app' => 'mcp-app-a', 'run_id' => $first['run_id']]);

    foreach ([$top, $reliability, $tree] as $metadataResponse) {
        $metadataResponse->assertOk()->assertJsonPath('result.isError', false);
        $encoded = (string) $metadataResponse->getContent();
        expect($encoded)->not->toContain(
            'RAW-CONTENT-CANARY-A', 'RAW-CONTENT-CANARY-B',
            'INSTRUCTIONS-', 'MESSAGE-', 'OUTPUT-', '"secret"',
        );
    }

    $content = mcp8Result(mcp8Call('/mcp/destructive', $credential, 'run_content', [
        'app' => 'mcp-app-a', 'run_id' => $first['run_id'],
    ]));
    expect(json_encode($content, JSON_THROW_ON_ERROR))->toContain('RAW-CONTENT-CANARY-A')
        ->not->toContain('RAW-CONTENT-CANARY-B')
        ->and($content['egress'])->toContain('Raw customer data');

    $wrongApp = mcp8Call('/mcp/destructive', $credential, 'run_content', [
        'app' => 'mcp-app-b', 'run_id' => $first['run_id'],
    ])->assertOk();
    expect($wrongApp->json('result.isError'))->toBeTrue()
        ->and($wrongApp->getContent())->not->toContain('RAW-CONTENT-CANARY-A');

    $searchA = mcp8Result(mcp8Call('/mcp/destructive', $credential, 'search_runs', [
        'app' => 'mcp-app-a', 'query' => 'RAW-CONTENT-CANARY-A', 'limit' => 10, 'offset' => 0,
    ]));
    $searchB = mcp8Result(mcp8Call('/mcp/destructive', $credential, 'search_runs', [
        'app' => 'mcp-app-b', 'query' => 'RAW-CONTENT-CANARY-A', 'limit' => 10, 'offset' => 0,
    ]));
    expect($searchA['runs'])->toHaveCount(1)->and($searchB['runs'])->toBe([]);

    mcp8Result(mcp8Call('/mcp/destructive', $credential, 'flag_run', [
        'run_id' => $first['run_id'], 'labels' => ['gold', 'gold'], 'rating' => '5', 'note' => 'MCP-NOTE-CANARY',
    ]));
    $labelled = mcp8Result(mcp8Call('/mcp/destructive', $credential, 'label_run', [
        'run_id' => $first['run_id'], 'labels' => ['reviewed'],
    ]));
    expect($labelled)->toBe(['labels' => ['reviewed'], 'rating' => 5, 'note' => 'MCP-NOTE-CANARY']);

    $created = mcp8Result(mcp8Call('/mcp/destructive', $credential, 'dataset_create', [
        'name' => 'MCP dataset', 'retention_days' => 30,
    ]));
    $item = mcp8Result(mcp8Call('/mcp/destructive', $credential, 'dataset_add', [
        'dataset_id' => $created['id'], 'run_id' => $first['run_id'],
    ]));
    expect($item['snapshot']['note'])->toBe('MCP-NOTE-CANARY')
        ->and($item['snapshot']['replay_fidelity'])->toBe('complete');

    DB::table('assay_record_content')->where('run_id', $first['run_id'])->delete();
    expect(resolve(DatasetManager::class)->dataset((string) $created['id'])['items'][0]['snapshot'])->toEqual($item['snapshot']);

    $datasets = mcp8Result(mcp8Call('/mcp/destructive', $credential, 'dataset_list', ['limit' => 1, 'offset' => 0]));
    expect($datasets['datasets'])->toHaveCount(1)->and($datasets['datasets'][0]['item_count'])->toBe(1);

    $export = mcp8Result(mcp8Call('/mcp/destructive', $credential, 'dataset_export', ['dataset_id' => $created['id']]));
    expect($export)->toHaveKeys(['id', 'download_url', 'egress'])
        ->and($export)->not->toHaveKey('jsonl')
        ->and(DB::table('assay_export_requests')->where('id', $export['id'])->value('requested_by'))
        ->toBe((string) $credential->credential->getKey());

    $beforePreview = [
        DB::table('assay_erasure_records')->count(),
        DB::table('assay_dataset_items')->count(),
        DB::table('assay_runs')->where('id', $first['run_id'])->value('subject'),
    ];
    $preview = mcp8Call('/mcp/destructive', $credential, 'delete_subject', [
        'app' => 'mcp-app-a', 'subject' => 'mcp-subject-a',
    ])->assertOk();
    expect($preview->json('result._meta.two_phase.phase'))->toBe('preview')
        ->and([
            DB::table('assay_erasure_records')->count(),
            DB::table('assay_dataset_items')->count(),
            DB::table('assay_runs')->where('id', $first['run_id'])->value('subject'),
        ])->toBe($beforePreview);

    $unknown = mcp8Call('/mcp/destructive', $credential, 'dataset_list', ['unexpected' => 'value'])->assertOk();
    expect($unknown->json('result.isError'))->toBeTrue()
        ->and($unknown->getContent())->toContain('Unexpected arguments')
        ->not->toContain('SQLSTATE');
});

it('denies every handle before validation query or effect when its Assay ability is absent', function (): void {
    $usage = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'mcp-direct-usage',
        'abilities' => [AssayCredentialAbility::Usage->value],
    ]);
    $content = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'mcp-direct-content',
        'abilities' => [AssayCredentialAbility::Content->value],
    ]);
    $queries = [];
    Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $assertDenied = function (MintedTestCredential $credential, string $tool, bool $preview = false) use (&$queries): void {
        $request = HttpRequest::create('/mcp/destructive', 'POST');
        $request->setUserResolver(static fn () => $credential->credential);
        app()->instance('request', $request);
        $instance = resolve($tool);
        $before = count($queries);

        try {
            $preview
                ? $instance->preview(new McpRequest(['unexpected' => 'must-not-validate']))
                : $instance->handle(new McpRequest(['unexpected' => 'must-not-validate']));
            test()->fail("{$tool} did not deny the missing Assay ability.");
        } catch (AuthorizationException $exception) {
            expect($exception->getMessage())->toContain('not authorized')
                ->and(count($queries))->toBe($before)
                ->and(resolve(ExceptionHandler::class)->render($request, $exception)->getStatusCode())->toBe(403);
        }
    };

    foreach ([UsageSummary::class, TopRuns::class, ReliabilitySummary::class, RunTree::class] as $tool) {
        $assertDenied($content, $tool);
    }

    foreach ([
        RunContent::class, SearchRuns::class, FlagRun::class, LabelRun::class,
        DatasetCreate::class, DatasetAdd::class, DatasetList::class, DatasetExport::class,
        DeleteSubject::class,
    ] as $tool) {
        $assertDenied($usage, $tool);
    }

    $assertDenied($usage, DeleteSubject::class, true);

    expect(DB::table('assay_datasets')->count())->toBe(0)
        ->and(DB::table('assay_erasure_records')->count())->toBe(0);
});

it('covers stored credential lifecycle purpose installation and person-cap projections on both doors', function (): void {
    $mint = fn (array $attributes = []) => $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'mcp-matrix-'.Str::lower(Str::random(8)),
        ...$attributes,
    ]);
    $usage = $mint(['abilities' => [AssayCredentialAbility::Usage->value]]);
    $content = $mint(['abilities' => [AssayCredentialAbility::Content->value]]);
    $both = $mint(['abilities' => [AssayCredentialAbility::Usage->value, AssayCredentialAbility::Content->value]]);
    $empty = $mint(['abilities' => []]);
    $installation = $mint([
        'subject_type' => SubjectType::Installation,
        'abilities' => [AssayCredentialAbility::Usage->value, AssayCredentialAbility::Content->value],
    ]);
    $wrongPurpose = $mint(['purpose' => CredentialPurpose::Consumption, 'subject_type' => SubjectType::Installation]);
    $revoked = $mint(['revoked_at' => now()]);
    $expired = $mint(['expires_at' => now()->subMinute()]);
    $pending = $mint(['status' => CredentialStatus::Pending]);
    $malformedBound = $mint(['subject_type' => SubjectType::UserPrincipal, 'user_id' => 'not-a-user-id']);

    foreach (['/mcp', '/mcp/destructive'] as $path) {
        expect(mcp8Names(mcp8List($path, $usage)))->toHaveCount(4)
            ->and(mcp8Names(mcp8List($path, $content)))->toHaveCount($path === '/mcp' ? 4 : 9)
            ->and(mcp8Names(mcp8List($path, $empty)))->toBe([]);

        foreach ([$wrongPurpose, $revoked, $expired, $pending, $malformedBound] as $refused) {
            mcp8List($path, $refused)->assertUnauthorized();
        }
    }

    expect(mcp8Names(mcp8List('/mcp', $both)))->toHaveCount(8)
        ->and(mcp8Names(mcp8List('/mcp/destructive', $both)))->toHaveCount(13)
        ->and(mcp8Names(mcp8List('/mcp/destructive', $installation)))->toHaveCount(13);
});

it('keeps MCP export ownership credential-specific and rechecks a bound persons current content cap', function (): void {
    $admin = User::query()->create(['name' => 'MCP Export Admin', 'email' => 'mcp-export-admin@example.test']);
    $admin->forceFill(['role' => UserRole::Admin->value, 'status' => 'active'])->save();
    $attributes = [
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => (string) $admin->getKey(),
        'user_id' => (string) $admin->getKey(),
        'abilities' => [AssayCredentialAbility::Usage->value, AssayCredentialAbility::Content->value],
    ];
    $first = $this->mintCredential($attributes);
    $second = $this->mintCredential($attributes);
    $source = seedMcp8Run('mcp-export-app', 'mcp-export-run', 'mcp-export-subject', 'EXPORT-RAW-CANARY');
    $dataset = resolve(DatasetManager::class)->create('MCP export ownership');
    resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);

    $export = mcp8Result(mcp8Call('/mcp/destructive', $first, 'dataset_export', ['dataset_id' => $dataset['id']]));
    expect(DB::table('assay_export_requests')->where('id', $export['id'])->value('requested_by'))
        ->toBe((string) $first->credential->getKey());

    $this->withHeader('Authorization', $first->bearerHeader())->get($export['download_url'])->assertOk();
    $this->withHeader('Authorization', $second->bearerHeader())->get($export['download_url'])->assertForbidden();

    ContentAccessOverride::query()->create([
        'actor_id' => (string) $admin->getKey(),
        'access' => ContentAccess::Denied,
        'set_by_actor_id' => (string) $admin->getKey(),
        'set_at' => now(),
    ]);
    $count = DB::table('assay_export_requests')->count();

    mcp8Call('/mcp/destructive', $first, 'dataset_export', ['dataset_id' => $dataset['id']])
        ->assertStatus(400)->assertJsonPath('error.code', -32602);
    expect(DB::table('assay_export_requests')->count())->toBe($count);
    $this->withHeader('Authorization', $first->bearerHeader())->get($export['download_url'])->assertForbidden();
});

it('bounds content decoding failures without leaking values or credential material to durable sinks', function (): void {
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'mcp-error-canary',
        'abilities' => [AssayCredentialAbility::Content->value],
    ]);
    $source = seedMcp8Run('mcp-error-app', 'mcp-error-run', 'mcp-error-subject', 'MCP-ERROR-RAW-CANARY');
    DB::statement('SAVEPOINT mcp_content_exception');
    DB::statement('DROP TABLE assay_messages');
    $logs = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event->message.' '.json_encode($event->context);
    });

    $response = mcp8Call('/mcp/destructive', $credential, 'run_content', [
        'app' => 'mcp-error-app', 'run_id' => $source['run_id'],
    ])->assertOk()->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'Assay could not complete the request.');
    DB::statement('ROLLBACK TO SAVEPOINT mcp_content_exception');
    $durable = json_encode([
        'response' => $response->getContent(),
        'logs' => $logs,
        'jobs' => DB::table('jobs')->get(),
        'failed_jobs' => DB::table('failed_jobs')->get(),
        'credential_audit' => DB::table('credential_audit_events')->get(),
        'app_audit' => DB::table('bfc_app_action_events')->get(),
    ], JSON_THROW_ON_ERROR);

    expect($durable)->not->toContain(
        $credential->plaintext(), hash('sha256', $credential->plaintext()),
        'MCP-ERROR-RAW-CANARY', 'SQLSTATE', 'QueryException', 'assay_messages',
    );
});

it('burns a valid confirmation before a protected erasure failure', function (): void {
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'mcp-burn-before-failure',
        'abilities' => [AssayCredentialAbility::Content->value],
    ]);
    $source = seedMcp8Run('mcp-burn-app', 'mcp-burn-run', 'mcp-burn-subject', 'MCP-BURN-RAW-CANARY');
    $arguments = ['app' => 'mcp-burn-app', 'subject' => 'mcp-burn-subject'];
    $preview = mcp8Call('/mcp/destructive', $credential, 'delete_subject', $arguments)->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    expect($confirmation)->toBeString();

    DB::table('assay_apps')->where('id', $source['app_id'])->update(['app_ref' => 'mcp-burn-app-renamed']);
    $failed = mcp8Call('/mcp/destructive', $credential, 'delete_subject', [...$arguments, 'confirm' => $confirmation])
        ->assertOk();
    expect($failed->json('result.isError'))->toBeTrue()
        ->and($failed->json('result._meta.two_phase.phase'))->toBe('executed')
        ->and(DB::table('assay_erasure_records')->count())->toBe(0)
        ->and(DB::table('assay_record_content')->where('run_id', $source['run_id'])->count())->toBeGreaterThan(0);

    mcp8Call('/mcp/destructive', $credential, 'delete_subject', [...$arguments, 'confirm' => $confirmation], 2)
        ->assertStatus(400)
        ->assertJsonPath('error.message', TwoPhaseConfirmationRefused::SPENT);
});

it('runs delete subject as a single-use argument and credential-bound two-phase operation without durable secret residue', function (): void {
    $first = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'mcp-delete-first',
        'abilities' => [AssayCredentialAbility::Content->value],
    ]);
    $second = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'mcp-delete-second',
        'abilities' => [AssayCredentialAbility::Content->value],
    ]);
    $source = seedMcp8Run('mcp-delete-app', 'mcp-delete-run', 'MCP-SUBJECT-SECRET-CANARY', 'MCP-DELETE-RAW-CANARY');
    $dataset = resolve(DatasetManager::class)->create('MCP delete dataset');
    resolve(DatasetManager::class)->add($dataset['id'], $source['run_id']);
    $usage = DB::table('assay_usage_metrics')->sum('value');
    $logs = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event->message.' '.json_encode($event->context);
    });

    $arguments = ['app' => 'mcp-delete-app', 'subject' => 'MCP-SUBJECT-SECRET-CANARY'];
    $preview = mcp8Call('/mcp/destructive', $first, 'delete_subject', $arguments)
        ->assertOk()->assertJsonPath('result._meta.two_phase.phase', 'preview');
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    expect($confirmation)->toBeString()
        ->and(DB::table('assay_erasure_records')->count())->toBe(0)
        ->and(DB::table('assay_dataset_items')->count())->toBe(1);
    $payload = [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'delete_subject', 'arguments' => [...$arguments, 'confirm' => $confirmation]],
    ];
    $http = HttpRequest::create(
        '/mcp/destructive',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode($payload, JSON_THROW_ON_ERROR),
    );
    $canonical = resolve(CanonicalToolArguments::class)->fromHttpRequest($http, JsonRpcRequest::from($payload));
    $confirmationSubject = Credential::class.'#'.(string) $first->credential->getKey();
    $confirmationStore = resolve(TwoPhaseConfirmationStore::class);
    expect(fn () => $confirmationStore->burn($confirmation, 'another_tool', $canonical, $confirmationSubject))
        ->toThrow(TwoPhaseConfirmationRefused::class, TwoPhaseConfirmationRefused::MISMATCHED);

    [$encodedOriginal] = explode('.', $confirmation, 2);
    $originalJson = base64_decode(strtr($encodedOriginal, '-_', '+/'), true);
    expect($originalJson)->toBeString();
    $expiredPayload = json_decode($originalJson, true, flags: JSON_THROW_ON_ERROR);
    $expiredPayload['exp'] = time() - 1;
    $binding = (new ReflectionMethod($confirmationStore, 'binding'))->invoke(
        $confirmationStore,
        $expiredPayload['id'],
        $expiredPayload['exp'],
        'delete_subject',
        $canonical,
        $confirmationSubject,
    );
    $expiredPayload['binding'] = $binding;
    $expiredJson = json_encode($expiredPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $signingKey = (new ReflectionMethod($confirmationStore, 'signingKey'))->invoke($confirmationStore);
    $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $expiredConfirmation = $encode($expiredJson).'.'.$encode(hash_hmac('sha256', $expiredJson, $signingKey, true));
    mcp8Call('/mcp/destructive', $first, 'delete_subject', [...$arguments, 'confirm' => $expiredConfirmation])
        ->assertStatus(400)
        ->assertJsonPath('error.message', TwoPhaseConfirmationRefused::EXPIRED);

    [$encodedPayload, $encodedSignature] = explode('.', $confirmation, 2);
    $forged = $encodedPayload.'.'.($encodedSignature[0] === 'A' ? 'B' : 'A').substr($encodedSignature, 1);

    foreach ([
        'wrong arguments' => [$first, [...$arguments, 'subject' => 'wrong-subject', 'confirm' => $confirmation]],
        'wrong credential' => [$second, [...$arguments, 'confirm' => $confirmation]],
        'malformed' => [$first, [...$arguments, 'confirm' => 'malformed-confirmation']],
        'forged' => [$first, [...$arguments, 'confirm' => $forged]],
    ] as [$credential, $attempt]) {
        $response = mcp8Call('/mcp/destructive', $credential, 'delete_subject', $attempt);
        expect($response->status())->toBe(400);
        $response->assertJsonPath('error.code', TwoPhaseCallTool::REFUSAL_CODE);
        expect(DB::table('assay_erasure_records')->count())->toBe(0)
            ->and(DB::table('assay_dataset_items')->count())->toBe(1);
    }

    $executed = mcp8Call('/mcp/destructive', $first, 'delete_subject', [...$arguments, 'confirm' => $confirmation])->assertOk();
    expect($executed->json('result._meta.two_phase.phase'))->toBe('executed')
        ->and(DB::table('assay_erasure_records')->count())->toBe(1)
        ->and(DB::table('assay_dataset_items')->count())->toBe(0)
        ->and(DB::table('assay_usage_metrics')->sum('value'))->toEqual($usage)
        ->and(DB::table('assay_record_content')->where('run_id', $source['run_id'])->count())->toBe(0)
        ->and(DB::table('assay_messages')->where('run_id', $source['run_id'])->count())->toBe(0);

    foreach ([2, 3] as $id) {
        mcp8Call('/mcp/destructive', $first, 'delete_subject', [...$arguments, 'confirm' => $confirmation], $id)
            ->assertStatus(400)->assertJsonPath('error.code', TwoPhaseCallTool::REFUSAL_CODE);
    }

    $durable = json_encode([
        'jobs' => DB::table('jobs')->get(),
        'failed_jobs' => DB::table('failed_jobs')->get(),
        'credential_audit' => DB::table('credential_audit_events')->get(),
        'app_audit' => DB::table('bfc_app_action_events')->get(),
        'logs' => $logs,
        'journal' => Storage::disk('erasure-journal')->allFiles(),
    ], JSON_THROW_ON_ERROR);
    expect($durable)->not->toContain(
        $first->plaintext(), hash('sha256', $first->plaintext()), $confirmation,
        'MCP-DELETE-RAW-CANARY', 'MCP-SUBJECT-SECRET-CANARY', 'SQLSTATE',
    );
});
