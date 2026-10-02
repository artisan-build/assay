<?php

declare(strict_types=1);

use App\Authorization\AssayCredentialAbility;
use App\Authorization\McpAccess;
use App\Mcp\AssayMcpServer;
use ArtisanBuild\BuiltForCloud\Console\Assertion;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\RequestAssertion;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Http\Middleware\AuthenticateMcp;
use ArtisanBuild\BuiltForCloud\Mcp\RequestEffectCeiling;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\Testing\McpProductAdmission;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Symfony\Component\HttpFoundation\Response;

uses(WithCredentials::class);

beforeEach(function (): void {
    config()->set('built-for-cloud.mcp.two_phase.cache_store', 'database');
    config()->set('assay.erasure.journal_disk', 'erasure-journal');
    config()->set('assay.erasure.journal_prefix', 'journal');
    config()->set('assay.erasure.active_key_version', 'v1');
    config()->set('assay.erasure.keys', ['v1' => 'MCP-ERASURE-KEY-CANARY-32-BYTES']);
    Storage::fake('erasure-journal');
});

/** @param array<string, mixed> $params */
function assayMcpRpc(string $path, string $bearer, string $method, array $params = [], int|string $id = 1): TestResponse
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params,
    ], ['Authorization' => $bearer]);
}

/** @return list<string> */
function assayMcpToolNames(TestResponse $response): array
{
    $response->assertOk();

    return array_column($response->json('result.tools'), 'name');
}

it('registers the two exact effect doors and advertises truthful metadata', function (): void {
    $read = Mcp::getWebServer('mcp');
    $destructive = Mcp::getWebServer('mcp/destructive');

    expect($read)->not->toBeNull()
        ->and($destructive)->not->toBeNull()
        ->and($read?->gatherMiddleware())->toContain('bfc.mcp:product,read')
        ->and($destructive?->gatherMiddleware())->toContain('bfc.mcp:product,destructive')
        ->and($read?->gatherMiddleware())->not->toContain('bfc.ability:mcp:read', 'bfc.ability:mcp:admin')
        ->and($destructive?->gatherMiddleware())->not->toContain('bfc.ability:mcp:read', 'bfc.ability:mcp:admin')
        ->and(config('built-for-cloud.mcp.path'))->toBe('/mcp')
        ->and(config('built-for-cloud.mcp.write_path'))->toBeNull()
        ->and(config('built-for-cloud.mcp.destructive_path'))->toBe('/mcp/destructive')
        ->and(config('built-for-cloud.mcp.delegated'))->toBeTrue()
        ->and(Mcp::getLocalServer('assay'))->toBeNull();

    $this->getJson('/bfc/meta')->assertOk()
        ->assertJsonPath('endpoints.mcp', '/mcp')
        ->assertJsonPath('endpoints.mcp_destructive', '/mcp/destructive')
        ->assertJson(fn ($json) => $json
            ->whereType('capabilities', 'array')
            ->etc());

    $capabilities = $this->getJson('/bfc/meta')->json('capabilities');
    expect($capabilities)->toContain('mcp-delegated', 'mcp-effect-scoped');
});

it('conforms all thirteen eligible tools and snapshots their wire metadata', function (): void {
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'mcp-conformance',
        'abilities' => [AssayCredentialAbility::Usage->value, AssayCredentialAbility::Content->value],
    ]);
    $request = Request::create('/mcp/destructive', 'POST', server: [
        'HTTP_AUTHORIZATION' => $credential->bearerHeader(),
    ]);
    $discovered = null;
    app()->instance('request', $request);
    app(AuthenticateMcp::class)->handle(
        $request,
        function (Request $request) use (&$discovered): Response {
            expect($request->user())->toBeInstanceOf(Credential::class)
                ->and(app(McpAccess::class)->allows(AssayCredentialAbility::Usage))->toBeTrue()
                ->and(app(McpAccess::class)->allows(AssayCredentialAbility::Content))->toBeTrue();
            McpDelegatedTools::assertConforms(AssayMcpServer::class);
            $discovered = McpDelegatedTools::discover(AssayMcpServer::class);

            return response('conforms');
        },
        'product',
        'destructive',
    );

    expect($discovered)->toBeArray();
    expect($discovered['violations'])->toBe([])
        ->and($discovered['tools'])->toHaveCount(13);

    $listed = assayMcpRpc('/mcp/destructive', $credential->bearerHeader(), 'tools/list')->assertOk();
    $tools = collect($listed->json('result.tools'))->keyBy('name');
    $expected = [
        'usage_summary' => 'read',
        'top_runs' => 'read',
        'reliability_summary' => 'read',
        'run_tree' => 'read',
        'run_content' => 'read',
        'search_runs' => 'read',
        'flag_run' => 'write',
        'label_run' => 'write',
        'dataset_create' => 'write',
        'dataset_add' => 'write',
        'dataset_list' => 'read',
        'dataset_export' => 'read',
        'delete_subject' => 'destructive',
    ];

    expect($tools->keys()->sort()->values()->all())->toBe(collect(array_keys($expected))->sort()->values()->all());

    foreach ($expected as $name => $effect) {
        expect($tools[$name]['_meta']['classification'])->toBe('content')
            ->and($tools[$name]['_meta']['effect'])->toBe($effect)
            ->and($tools[$name]['inputSchema']['additionalProperties'])->toBeFalse()
            ->and($tools[$name]['annotations'])->not->toBe([]);
    }

    expect($tools['delete_subject']['inputSchema']['properties'])->toHaveKey('confirm')
        ->and($tools['delete_subject']['_meta']['two_phase'])->toBe([
            'confirmationArgument' => 'confirm',
            'protocolVersion' => 1,
        ]);
});

it('hides unauthorized tools and enforces the read effect ceiling before execution', function (): void {
    $usage = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'usage-only',
        'abilities' => [AssayCredentialAbility::Usage->value],
    ]);
    $content = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'content-only',
        'abilities' => [AssayCredentialAbility::Content->value],
    ]);
    $both = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'both',
        'abilities' => [AssayCredentialAbility::Usage->value, AssayCredentialAbility::Content->value],
    ]);
    $ingest = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'ingest',
        'abilities' => null,
    ]);

    expect(assayMcpToolNames(assayMcpRpc('/mcp', $usage->bearerHeader(), 'tools/list')))->toBe([
        'usage_summary', 'top_runs', 'reliability_summary', 'run_tree',
    ]);
    expect(assayMcpToolNames(assayMcpRpc('/mcp', $content->bearerHeader(), 'tools/list')))->toBe([
        'run_content', 'search_runs', 'dataset_list', 'dataset_export',
    ]);
    expect(assayMcpToolNames(assayMcpRpc('/mcp/destructive', $both->bearerHeader(), 'tools/list')))->toHaveCount(13);

    assayMcpRpc('/mcp', $both->bearerHeader(), 'tools/call', [
        'name' => 'dataset_create',
        'arguments' => ['name' => 'must-not-exist'],
    ])->assertStatus(400)
        ->assertJsonPath('error.code', RequestEffectCeiling::REFUSAL_CODE)
        ->assertJsonPath('error.message', RequestEffectCeiling::REFUSAL_MESSAGE);
    expect(DB::table('assay_datasets')->count())->toBe(0);

    foreach (['/mcp', '/mcp/destructive'] as $path) {
        assayMcpRpc($path, $ingest->bearerHeader(), 'tools/list')->assertUnauthorized();
        assayMcpRpc($path, 'Bearer unknown-mcp-secret', 'tools/list')->assertUnauthorized();
    }
});

it('projects delegated and person-bound principals without browser-session widening', function (): void {
    $member = User::query()->create(['name' => 'MCP Member', 'email' => 'mcp-member@example.test']);
    $member->forceFill(['role' => UserRole::Member->value, 'status' => 'active'])->save();
    $bound = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => (string) $member->getKey(),
        'user_id' => (string) $member->getKey(),
        'abilities' => [AssayCredentialAbility::Usage->value, AssayCredentialAbility::Content->value],
    ]);
    $owner = User::query()->create(['name' => 'Browser Owner', 'email' => 'browser-owner@example.test']);
    $owner->forceFill(['role' => UserRole::Owner->value, 'status' => 'active'])->save();

    $listed = $this->actingAs($owner);
    expect(assayMcpToolNames($listed->withHeader('Authorization', $bound->bearerHeader())->postJson('/mcp/destructive', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
    ])))->toBe(['usage_summary', 'top_runs', 'reliability_summary', 'run_tree']);

    foreach ([[ConsoleRole::Member, 4], [ConsoleRole::Admin, 13]] as [$role, $count]) {
        $actor = DelegatedActor::query()->create([
            'identity_hash' => DelegatedActor::identityHash('mcp-test', $role->value),
            'issuer' => 'mcp-test',
            'subject' => $role->value,
            'last_handoff_display_name' => 'MCP '.$role->value,
            'last_handoff_role' => $role,
        ]);
        $request = Request::create('/mcp/delegated');
        RequestAssertion::publish($request, $actor, Assertion::fromVerifiedClaims(
            issuer: 'mcp-test',
            subject: $role->value,
            displayName: 'MCP '.$role->value,
            role: $role,
            onBehalfOf: 'Assay tests',
            audience: 'assay-test',
            issuedAt: CarbonImmutable::now()->subSecond(),
            expiresAt: CarbonImmutable::now()->addMinute(),
            keyId: 'mcp-key',
            id: (string) Str::uuid(),
        ));
        app()->instance('request', $request);
        RequestEffectCeiling::publish($request, 'destructive');

        $server = app()->make(AssayMcpServer::class, ['transport' => new FakeTransporter]);
        $server->start();
        expect($server->createContext()->tools())->toHaveCount($count);
    }
});

it('proves the shipped product admission helper', function (): void {
    McpProductAdmission::assert();
});
