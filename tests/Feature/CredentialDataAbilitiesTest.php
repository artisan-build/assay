<?php

declare(strict_types=1);

use App\Authorization\AssayCredentialAbility;
use App\Enums\ContentAccess;
use App\Http\Middleware\EnsureAssayAccess;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ContentAccessOverride;
use App\Services\DatasetManager;
use ArtisanBuild\BuiltForCloud\Actions\MintCredential;
use ArtisanBuild\BuiltForCloud\Audit\AppActionEvent;
use ArtisanBuild\BuiltForCloud\Console\Assertion;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\RequestAssertion;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialAuditEvent;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\Exceptions\InvalidCredentialInput;
use ArtisanBuild\BuiltForCloud\MintOptions;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\CredentialAbilityAssertions;
use ArtisanBuild\BuiltForCloud\Testing\FakeCredentialAbilityRegistry;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as FrameworkRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;

uses(WithCredentials::class);

function createPr7bUser(string $name, UserRole $role, string $status = 'active'): User
{
    $user = User::query()->create([
        'name' => $name,
        'email' => Str::slug($name).'-'.Str::lower(Str::random(8)).'@example.test',
    ]);
    $user->forceFill(['role' => $role->value, 'status' => $status])->save();

    return $user;
}

/** @return array<string, string> */
function pr7bProtectedRoutes(): array
{
    return [
        'assay.dashboard' => 'usage',
        'assay.dashboard.usage-over-time' => 'usage',
        'assay.dashboard.usage-over-time.csv' => 'usage',
        'assay.dashboard.usage-by-agent' => 'usage',
        'assay.dashboard.usage-by-agent.csv' => 'usage',
        'assay.dashboard.usage-by-subject' => 'usage',
        'assay.dashboard.usage-by-subject.csv' => 'usage',
        'assay.dashboard.top-runs' => 'usage',
        'assay.dashboard.top-runs.csv' => 'usage',
        'assay.dashboard.reliability' => 'usage',
        'assay.dashboard.reliability.csv' => 'usage',
        'assay.dashboard.latency' => 'usage',
        'assay.dashboard.latency.csv' => 'usage',
        'assay.dashboard.pipeline-health' => 'usage',
        'assay.dashboard.pipeline-health.csv' => 'usage',
        'assay.risk' => 'usage',
        'assay.runs.tree' => 'content',
        'assay.access.index' => 'content',
        'assay.access.set' => 'content',
        'assay.access.reset' => 'content',
        'assay.runs.curate' => 'content',
        'assay.runs.flag' => 'content',
        'assay.datasets.index' => 'content',
        'assay.datasets.create' => 'content',
        'assay.datasets.show' => 'content',
        'assay.datasets.items.add' => 'content',
        'assay.datasets.exports.request' => 'content',
        'assay.exports.download' => 'content',
    ];
}

it('keeps browser forgery protection while routing Assay bearers to the access gate', function (): void {
    $web = resolve(Kernel::class)->getMiddlewareGroups()['web'];
    $replacement = array_search(PreventRequestForgery::class, $web, true);

    expect($replacement)->toBeInt()
        ->and($web[$replacement - 1])->toBe(ShareErrorsFromSession::class)
        ->and($web[$replacement + 1])->toBe(SubstituteBindings::class)
        ->and($web)->not->toContain(FrameworkRequestForgery::class);

    $protectedRoute = Route::getRoutes()->getByName('assay.datasets.create');
    expect($protectedRoute)->toBeInstanceOf(RoutingRoute::class);

    $request = Request::create('/assay/datasets', 'POST');
    $request->setRouteResolver(static fn (): RoutingRoute => $protectedRoute);

    foreach (['Bearer credential', 'bearer ', '  Bearer credential'] as $authorization) {
        $request->headers->set('Authorization', $authorization);
        expect(PreventRequestForgery::shouldBypassForAssayBearer($request))->toBeTrue();
    }

    $request->headers->set('Authorization', 'Basic credential');
    expect(PreventRequestForgery::shouldBypassForAssayBearer($request))->toBeFalse();

    $ordinaryRoute = new RoutingRoute(['POST'], '/ordinary', static fn (): string => 'ordinary');
    $request->headers->set('Authorization', 'Bearer credential');
    $request->setRouteResolver(static fn (): RoutingRoute => $ordinaryRoute);
    $request->setLaravelSession(new Store('pr7b-forgery-proof', new ArraySessionHandler(60)));
    expect(PreventRequestForgery::shouldBypassForAssayBearer($request))->toBeFalse();

    app()->detectEnvironment(static fn (): string => 'production');

    try {
        expect(fn () => resolve(PreventRequestForgery::class)->handle(
            $request,
            static fn (): never => throw new LogicException('Browser request bypassed forgery protection.'),
        ))->toThrow(TokenMismatchException::class);
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }
});

it('registers the exact Assay vocabulary and accepts it through released model and mint validation', function (): void {
    CredentialAbilityAssertions::assertRegistered(
        app(),
        AssayCredentialAbility::Usage->value,
        AssayCredentialAbility::Content->value,
    );

    $direct = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'registered-model-save',
        'abilities' => [AssayCredentialAbility::Usage->value],
    ])->credential;

    $mint = resolve(MintCredential::class)(
        new Subject(SubjectType::ExternalConsumer, 'registered-mint'),
        new MintOptions(
            purpose: CredentialPurpose::Mcp,
            abilities: [AssayCredentialAbility::Usage->value, AssayCredentialAbility::Content->value],
        ),
    );

    expect($direct->hasAbility(AssayCredentialAbility::Usage->value))->toBeTrue()
        ->and($direct->hasAbility(AssayCredentialAbility::Content->value))->toBeFalse()
        ->and($direct->hasAbility('assay.usage.extra'))->toBeFalse()
        ->and($mint->summary->abilities)->toBe([
            AssayCredentialAbility::Usage->value,
            AssayCredentialAbility::Content->value,
        ]);

    $credentialCount = Credential::query()->count();
    $auditCount = CredentialAuditEvent::query()->count();

    expect(fn () => $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'unregistered-model-save',
        'abilities' => ['assay.unregistered'],
    ]))->toThrow(InvalidCredentialInput::class, 'Unknown credential ability');
    expect(fn () => resolve(MintCredential::class)(
        new Subject(SubjectType::ExternalConsumer, 'unregistered-mint'),
        new MintOptions(purpose: CredentialPurpose::Mcp, abilities: ['assay.unregistered']),
    ))->toThrow(InvalidCredentialInput::class, 'Unknown credential ability');
    expect(fn () => MintOptions::fromInput([
        'purpose' => CredentialPurpose::Mcp->value,
        'abilities' => ['assay.*'],
    ]))->toThrow(InvalidCredentialInput::class, 'Unknown credential ability');

    expect(Credential::query()->count())->toBe($credentialCount)
        ->and(CredentialAuditEvent::query()->count())->toBe($auditCount);

    $fake = FakeCredentialAbilityRegistry::install(app());
    expect($direct->hasAbility(AssayCredentialAbility::Usage->value))->toBeFalse();
    $fake->register(AssayCredentialAbility::Usage->value);
    expect($direct->hasAbility(AssayCredentialAbility::Usage->value))->toBeTrue();
});

it('distinguishes bearer authentication from authorization and matches only literal abilities', function (): void {
    $missing = (string) Str::uuid();
    $usageUrl = route('assay.dashboard.top-runs', absolute: false);
    $contentUrl = route('assay.runs.tree', ['run' => $missing], false);
    $mint = fn (array $attributes = []) => $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'literal-'.Str::lower(Str::random(8)),
        ...$attributes,
    ]);

    $usage = $mint(['abilities' => [AssayCredentialAbility::Usage->value]]);
    $contentOnly = $mint(['abilities' => [AssayCredentialAbility::Content->value]]);
    $both = $mint(['abilities' => [AssayCredentialAbility::Usage->value, AssayCredentialAbility::Content->value]]);
    $empty = $mint(['abilities' => []]);
    $null = $mint(['abilities' => null]);
    $ingest = $mint([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'abilities' => null,
    ]);
    $unrelated = $mint(['purpose' => CredentialPurpose::Consumption]);
    $revoked = $mint(['revoked_at' => now()]);
    $expired = $mint(['expires_at' => now()->subMinute()]);
    $pending = $mint(['status' => CredentialStatus::Pending]);

    $this->withHeader('Authorization', $usage->bearerHeader())->getJson($usageUrl)->assertOk();
    $this->withHeader('Authorization', $usage->bearerHeader())->getJson($contentUrl)->assertForbidden();
    $this->withHeader('Authorization', $contentOnly->bearerHeader())->getJson($usageUrl)->assertForbidden();
    $this->withHeader('Authorization', $contentOnly->bearerHeader())->getJson($contentUrl)->assertNotFound();
    $this->withHeader('Authorization', $both->bearerHeader())->getJson($usageUrl)->assertOk();
    $this->withHeader('Authorization', $both->bearerHeader())->getJson($contentUrl)->assertNotFound();

    foreach ([$empty, $null, $ingest, $unrelated] as $unauthorized) {
        $this->withHeader('Authorization', $unauthorized->bearerHeader())->getJson($usageUrl)->assertForbidden();
    }

    foreach ([$revoked, $expired, $pending] as $unauthenticated) {
        $this->withHeader('Authorization', $unauthenticated->bearerHeader())->getJson($usageUrl)->assertUnauthorized();
    }

    $this->withToken('unknown-pr7b-secret')->getJson($usageUrl)->assertUnauthorized();

    $sessionOwner = createPr7bUser('Bearer Precedence Owner', UserRole::Owner);
    $this->actingAs($sessionOwner)
        ->withHeader('Authorization', $usage->bearerHeader())
        ->getJson($contentUrl)
        ->assertForbidden();
});

it('caps person-bound credentials by current person access without granting missing abilities', function (): void {
    $owner = createPr7bUser('Bound Owner', UserRole::Owner);
    $admin = createPr7bUser('Bound Admin', UserRole::Admin);
    $member = createPr7bUser('Bound Member', UserRole::Member);
    $grantedMember = createPr7bUser('Bound Granted Member', UserRole::Member);
    $deniedAdmin = createPr7bUser('Bound Denied Admin', UserRole::Admin);
    $inactive = createPr7bUser('Bound Inactive Member', UserRole::Member, 'inactive');
    $removed = createPr7bUser('Bound Removed Member', UserRole::Member);

    ContentAccessOverride::query()->create([
        'actor_id' => (string) $grantedMember->getKey(),
        'access' => ContentAccess::Granted,
        'set_by_actor_id' => (string) $owner->getKey(),
        'set_at' => now(),
    ]);
    ContentAccessOverride::query()->create([
        'actor_id' => (string) $deniedAdmin->getKey(),
        'access' => ContentAccess::Denied,
        'set_by_actor_id' => (string) $owner->getKey(),
        'set_at' => now(),
    ]);

    $mintFor = fn (User $user, array $abilities = [AssayCredentialAbility::Usage->value, AssayCredentialAbility::Content->value]) => $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => (string) $user->getKey(),
        'user_id' => (string) $user->getKey(),
        'abilities' => $abilities,
    ]);
    $credentials = [
        'owner' => $mintFor($owner),
        'admin' => $mintFor($admin),
        'member' => $mintFor($member),
        'granted-member' => $mintFor($grantedMember),
        'denied-admin' => $mintFor($deniedAdmin),
        'inactive' => $mintFor($inactive),
        'removed' => $mintFor($removed),
        'content-only-owner' => $mintFor($owner, [AssayCredentialAbility::Content->value]),
    ];
    $removed->delete();

    $usageUrl = route('assay.dashboard.top-runs', absolute: false);
    $contentUrl = route('assay.runs.tree', ['run' => (string) Str::uuid()], false);

    foreach (['owner', 'admin', 'member', 'granted-member', 'denied-admin'] as $name) {
        $this->withHeader('Authorization', $credentials[$name]->bearerHeader())->getJson($usageUrl)->assertOk();
    }

    foreach (['owner', 'admin', 'granted-member'] as $name) {
        $this->withHeader('Authorization', $credentials[$name]->bearerHeader())->getJson($contentUrl)->assertNotFound();
    }

    foreach (['member', 'denied-admin', 'inactive', 'removed'] as $name) {
        $this->withHeader('Authorization', $credentials[$name]->bearerHeader())->getJson($contentUrl)->assertForbidden();
    }

    foreach (['inactive', 'removed', 'content-only-owner'] as $name) {
        $this->withHeader('Authorization', $credentials[$name]->bearerHeader())->getJson($usageUrl)->assertForbidden();
    }

    ContentAccessOverride::query()->create([
        'actor_id' => (string) $admin->getKey(),
        'access' => ContentAccess::Denied,
        'set_by_actor_id' => (string) $owner->getKey(),
        'set_at' => now(),
    ]);
    $this->withHeader('Authorization', $credentials['admin']->bearerHeader())->getJson($contentUrl)->assertForbidden();
    $this->withHeader('Authorization', $credentials['admin']->bearerHeader())->getJson($usageUrl)->assertOk();

    ContentAccessOverride::query()->whereKey($admin->getKey())->delete();
    $admin->forceFill(['role' => UserRole::Member->value])->save();
    $this->withHeader('Authorization', $credentials['admin']->bearerHeader())->getJson($contentUrl)->assertForbidden();
});

it('projects a verified delegated assertion through the same central decision', function (): void {
    $actor = DelegatedActor::query()->create([
        'identity_hash' => DelegatedActor::identityHash('pr7b-issuer', 'pr7b-operator'),
        'issuer' => 'pr7b-issuer',
        'subject' => 'pr7b-operator',
        'last_handoff_display_name' => 'PR7b Operator',
        'last_handoff_role' => ConsoleRole::Admin,
    ]);
    $request = Request::create('/assay/delegated-proof');
    RequestAssertion::publish($request, $actor, Assertion::fromVerifiedClaims(
        issuer: 'pr7b-issuer',
        subject: 'pr7b-operator',
        displayName: 'PR7b Operator',
        role: ConsoleRole::Admin,
        onBehalfOf: 'PR7b Agency',
        audience: 'assay-test',
        issuedAt: CarbonImmutable::now()->subSecond(),
        expiresAt: CarbonImmutable::now()->addMinute(),
        keyId: 'pr7b-key',
        id: (string) Str::uuid(),
    ));
    app()->instance('request', $request);

    $response = resolve(EnsureAssayAccess::class)->handle(
        $request,
        static fn () => response('delegated'),
        'content',
    );

    expect($response->getStatusCode())->toBe(200)
        ->and(EnsureAssayAccess::principal($request)->delegated)->toBeTrue()
        ->and(EnsureAssayAccess::decision($request)->content)->toBeTrue();
});

it('keeps credential exports token-owned and rechecks the current content cap without leaking secrets', function (): void {
    $owner = createPr7bUser('Export Cap Owner', UserRole::Owner);
    $admin = createPr7bUser('Export Cap Admin', UserRole::Admin);
    $target = createPr7bUser('Credential Management Target', UserRole::Member);
    $abilities = [AssayCredentialAbility::Usage->value, AssayCredentialAbility::Content->value];
    $mint = fn () => $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => (string) $admin->getKey(),
        'user_id' => (string) $admin->getKey(),
        'abilities' => $abilities,
    ]);
    $first = $mint();
    $second = $mint();
    $plaintexts = [$first->plaintext(), $second->plaintext()];
    $hashes = array_map(static fn (string $secret): string => hash('sha256', $secret), $plaintexts);
    $logs = [];
    Log::listen(static function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event->message.' '.json_encode($event->context, JSON_THROW_ON_ERROR);
    });

    $beforeOverrides = ContentAccessOverride::query()->count();
    $beforeAudits = AppActionEvent::query()->count();
    $deniedSet = $this->withHeader('Authorization', $first->bearerHeader())
        ->putJson(route('assay.access.set', $target), ['access' => 'granted'])
        ->assertForbidden();
    $deniedReset = $this->withHeader('Authorization', $first->bearerHeader())
        ->deleteJson(route('assay.access.reset', $target))
        ->assertForbidden();
    expect(ContentAccessOverride::query()->count())->toBe($beforeOverrides)
        ->and(AppActionEvent::query()->count())->toBe($beforeAudits);

    $appId = (string) Str::uuid();
    DB::table('assay_apps')->insert([
        'id' => $appId,
        'app_ref' => 'credential-export-app',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $dataset = resolve(DatasetManager::class)->create('Credential export dataset');
    DB::table('assay_dataset_items')->insert([
        'id' => (string) Str::uuid(),
        'dataset_id' => $dataset['id'],
        'app_id' => $appId,
        'source_run_id' => null,
        'subject_key_version' => null,
        'subject_tombstone' => null,
        'source_occurred_at' => now(),
        'snapshot' => json_encode(['observed_output' => ['text' => 'EXPORT-CONTENT-CANARY']], JSON_THROW_ON_ERROR),
        'added_at' => now(),
        'expires_at' => null,
    ]);

    $request = $this->withHeader('Authorization', $first->bearerHeader())
        ->postJson(route('assay.datasets.exports.request', $dataset['id']))
        ->assertCreated();
    $downloadUrl = (string) $request->json('download_url');
    $exportId = (string) $request->json('id');
    $successfulDownload = $this->withHeader('Authorization', $first->bearerHeader())
        ->get($downloadUrl)
        ->assertOk()
        ->assertSee('EXPORT-CONTENT-CANARY');
    $otherCredential = $this->withHeader('Authorization', $second->bearerHeader())
        ->getJson($downloadUrl)
        ->assertForbidden();

    $exportRow = DB::table('assay_export_requests')->where('id', $exportId)->sole();
    expect((string) $exportRow->requested_by)->toBe($first->credential->id)
        ->and((string) $exportRow->requested_by)->not->toBe((string) $admin->getKey());

    ContentAccessOverride::query()->create([
        'actor_id' => (string) $admin->getKey(),
        'access' => ContentAccess::Denied,
        'set_by_actor_id' => (string) $owner->getKey(),
        'set_at' => now(),
    ]);
    $beforeDeniedMint = DB::table('assay_export_requests')->count();
    $deniedMint = $this->withHeader('Authorization', $first->bearerHeader())
        ->postJson(route('assay.datasets.exports.request', $dataset['id']))
        ->assertForbidden();
    $deniedDownload = $this->withHeader('Authorization', $first->bearerHeader())
        ->getJson($downloadUrl)
        ->assertForbidden()
        ->assertDontSee('EXPORT-CONTENT-CANARY');
    expect(DB::table('assay_export_requests')->count())->toBe($beforeDeniedMint);

    $durable = json_encode([
        'exports' => DB::table('assay_export_requests')->get()->all(),
        'jobs' => DB::table('jobs')->get()->all(),
        'failed_jobs' => DB::table('failed_jobs')->get()->all(),
        'logs' => $logs,
    ], JSON_THROW_ON_ERROR);
    $responses = implode('', array_map(
        static fn ($response): string => (string) $response->getContent(),
        [$deniedSet, $deniedReset, $request, $successfulDownload, $otherCredential, $deniedMint, $deniedDownload],
    ));

    foreach ([...$plaintexts, ...$hashes] as $canary) {
        expect($durable)->not->toContain($canary)
            ->and($responses)->not->toContain($canary);
    }
});

it('inventories every shipped Assay route and keeps the principal matrix on the central decision', function (): void {
    $protected = pr7bProtectedRoutes();
    $actual = collect(Route::getRoutes()->getRoutes())
        ->map(static fn ($route): ?string => $route->getName())
        ->filter(static fn (?string $name): bool => is_string($name) && str_starts_with($name, 'assay.'))
        ->sort()->values()->all();
    $expected = [...array_keys($protected), 'assay.ingest', 'assay.capabilities'];
    sort($expected);

    expect($actual)->toBe($expected);

    foreach ($protected as $name => $ability) {
        expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())
            ->toContain('assay.access:'.$ability);
    }

    expect(Route::getRoutes()->getByName('assay.ingest')?->gatherMiddleware())->not->toContain('assay.access:usage', 'assay.access:content')
        ->and(Route::getRoutes()->getByName('assay.capabilities')?->gatherMiddleware())->not->toContain('assay.access:usage', 'assay.access:content');

    $owner = createPr7bUser('Inventory Owner', UserRole::Owner);
    $member = createPr7bUser('Inventory Member', UserRole::Member);
    $narrowedAdmin = createPr7bUser('Inventory Narrowed Admin', UserRole::Admin);
    $grantedMember = createPr7bUser('Inventory Granted Member', UserRole::Member);
    foreach ([[$narrowedAdmin, ContentAccess::Denied], [$grantedMember, ContentAccess::Granted]] as [$user, $access]) {
        ContentAccessOverride::query()->create([
            'actor_id' => (string) $user->getKey(),
            'access' => $access,
            'set_by_actor_id' => (string) $owner->getKey(),
            'set_at' => now(),
        ]);
    }
    $usage = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'matrix-usage',
        'abilities' => [AssayCredentialAbility::Usage->value],
    ]);
    $content = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'matrix-content',
        'abilities' => [AssayCredentialAbility::Usage->value, AssayCredentialAbility::Content->value],
    ]);
    $contentOnly = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'matrix-content-only',
        'abilities' => [AssayCredentialAbility::Content->value],
    ]);
    $ingest = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'matrix-ingest',
    ]);
    $missing = (string) Str::uuid();
    $usageUrl = route('assay.dashboard.top-runs', absolute: false);
    $contentUrl = route('assay.runs.tree', ['run' => $missing], false);

    foreach ([$owner, $member, $narrowedAdmin, $grantedMember] as $person) {
        $this->withHeader('Authorization', '')->actingAs($person)->getJson($usageUrl)->assertOk();
    }
    foreach ([$owner, $grantedMember] as $person) {
        $this->withHeader('Authorization', '')->actingAs($person)->getJson($contentUrl)->assertNotFound();
    }
    foreach ([$member, $narrowedAdmin] as $person) {
        $this->withHeader('Authorization', '')->actingAs($person)->getJson($contentUrl)->assertForbidden();
    }

    $usageSurfaces = [];
    foreach ($protected as $name => $ability) {
        if ($ability === 'usage') {
            $usageSurfaces[] = ['GET', route($name, absolute: false), [], 200];
        }
    }
    $contentSurfaces = [
        ['GET', route('assay.runs.tree', ['run' => $missing], false), [], 404],
        ['GET', route('assay.access.index', absolute: false), [], 403],
        ['PUT', route('assay.access.set', ['actor' => $member->getKey()], false), ['access' => 'granted'], 403],
        ['DELETE', route('assay.access.reset', ['actor' => $member->getKey()], false), [], 403],
        ['GET', route('assay.runs.curate', ['run' => $missing], false), [], 404],
        ['PUT', route('assay.runs.flag', ['run' => $missing], false), ['labels' => []], 404],
        ['GET', route('assay.datasets.index', absolute: false), [], 200],
        ['POST', route('assay.datasets.create', absolute: false), [], 422],
        ['GET', route('assay.datasets.show', ['dataset' => $missing], false), [], 404],
        ['POST', route('assay.datasets.items.add', ['dataset' => $missing], false), [], 422],
        ['POST', route('assay.datasets.exports.request', ['dataset' => $missing], false), [], 404],
        ['GET', route('assay.exports.download', ['export' => $missing], false), [], 404],
    ];
    $requestAs = function (string $header, array $surface) {
        [$method, $url, $data] = $surface;

        return $this->withHeader('Authorization', $header)->json($method, $url, $data);
    };

    foreach ([...$usageSurfaces, ...$contentSurfaces] as $surface) {
        $requestAs($ingest->bearerHeader(), $surface)->assertForbidden();
    }
    foreach ($usageSurfaces as $surface) {
        $requestAs($usage->bearerHeader(), $surface)->assertStatus($surface[3]);
        $requestAs($content->bearerHeader(), $surface)->assertStatus($surface[3]);
        $requestAs($contentOnly->bearerHeader(), $surface)->assertForbidden();
    }
    foreach ($contentSurfaces as $surface) {
        $requestAs($usage->bearerHeader(), $surface)->assertForbidden();
        $requestAs($content->bearerHeader(), $surface)->assertStatus($surface[3]);
        $requestAs($contentOnly->bearerHeader(), $surface)->assertStatus($surface[3]);
    }

    $this->withHeader('Authorization', '')->getJson(route('assay.capabilities'))->assertOk();
    $this->withHeader('Authorization', $ingest->bearerHeader())->postJson(route('assay.ingest'), [])->assertUnprocessable();
    $this->withHeader('Authorization', $usage->bearerHeader())->postJson(route('assay.ingest'), [])->assertUnauthorized();
    $this->withHeader('Authorization', $content->bearerHeader())->postJson(route('assay.ingest'), [])->assertUnauthorized();
});
