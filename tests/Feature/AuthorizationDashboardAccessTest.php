<?php

declare(strict_types=1);

use App\Authorization\AssayAccessPolicy;
use App\Authorization\ContentAccessSource;
use App\Enums\AppAction;
use App\Enums\ContentAccess;
use App\Http\Middleware\EnsureAssayAccess;
use App\Jobs\ProcessUsageEnvelope;
use App\Models\ContentAccessOverride;
use App\Services\ContentAccessOverrideService;
use App\Services\UsageIngestProcessor;
use ArtisanBuild\BuiltForCloud\Audit\AppActionEvent;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\DelegatedClaims;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\EnvelopeFactory;

uses(WithCredentials::class);

function createAssayUser(string $name, UserRole $role, string $status = 'active'): User
{
    $user = User::query()->create([
        'name' => $name,
        'email' => Str::slug($name).'-'.Str::lower(Str::random(8)).'@example.test',
    ]);
    $user->forceFill(['role' => $role->value, 'status' => $status])->save();

    return $user;
}

/** @return list<string> */
function pr5UsageUrls(): array
{
    $names = [
        'assay.dashboard',
        'assay.dashboard.usage-over-time',
        'assay.dashboard.usage-over-time.csv',
        'assay.dashboard.usage-by-agent',
        'assay.dashboard.usage-by-agent.csv',
        'assay.dashboard.usage-by-subject',
        'assay.dashboard.usage-by-subject.csv',
        'assay.dashboard.top-runs',
        'assay.dashboard.top-runs.csv',
        'assay.dashboard.reliability',
        'assay.dashboard.reliability.csv',
        'assay.dashboard.latency',
        'assay.dashboard.latency.csv',
        'assay.dashboard.pipeline-health',
        'assay.dashboard.pipeline-health.csv',
        'assay.risk',
    ];

    return array_map(static fn (string $name): string => route($name, absolute: false), $names);
}

/** @return list<string> */
function pr5ReadUrls(string $runId): array
{
    $urls = pr5UsageUrls();
    $urls[] = route('assay.access.index', absolute: false);
    $urls[] = route('assay.runs.tree', ['run' => $runId], false);

    return $urls;
}

function seedLinkedRun(): string
{
    $envelope = EnvelopeFactory::envelope([
        EnvelopeFactory::record([
            'type' => 'run.start',
            'invocation_id' => 'linked-run',
            'agent' => 'App\\Ai\\LinkedAgent',
            'subject' => 'subject-safe',
            'model' => ['provider' => 'provider-a', 'requested' => 'requested-a'],
        ]),
        EnvelopeFactory::record([
            'type' => 'step.end',
            'invocation_id' => 'linked-run',
            'step' => 0,
            'usage' => ['input_tokens' => 3],
            'duration_ms' => 2.5,
            'model' => ['provider' => 'provider-a', 'requested' => 'requested-a', 'responded' => 'responded-a'],
        ]),
        EnvelopeFactory::record([
            'type' => 'run.end',
            'invocation_id' => 'linked-run',
            'outcome' => 'completed',
            'usage' => ['input_tokens' => 3],
            'model' => ['provider' => 'provider-a', 'requested' => 'requested-a', 'responded' => 'responded-a'],
        ]),
    ]);

    ProcessUsageEnvelope::fromContract('linked-app', '2026-10-01T12:00:10.000000+00:00', $envelope)
        ->handle(resolve(UsageIngestProcessor::class));

    return (string) DB::table('assay_runs')->where('invocation_id', 'linked-run')->value('id');
}

it('projects explicit usage and content abilities for defaults, overrides, absence, and delegated assertions', function (): void {
    $owner = createAssayUser('Policy Owner', UserRole::Owner);
    $admin = createAssayUser('Policy Admin', UserRole::Admin);
    $member = createAssayUser('Policy Member', UserRole::Member);
    $absent = createAssayUser('Policy Absent', UserRole::Admin, 'inactive');
    $policy = resolve(AssayAccessPolicy::class);

    $decisions = collect([$owner, $admin, $member, $absent])->map(
        fn (User $user) => $policy->decide(ActingPrincipal::local('web', $user), $user),
    );

    expect($decisions->map(fn ($access): array => [$access->usage, $access->content])->all())->toBe([
        [true, true],
        [true, true],
        [true, false],
        [false, false],
    ])->and(ContentAccessOverride::query()->count())->toBe(0);

    ContentAccessOverride::query()->create([
        'actor_id' => (string) $member->getKey(),
        'access' => ContentAccess::Granted,
        'set_by_actor_id' => (string) $owner->getKey(),
        'set_at' => now(),
        'reason' => 'Stored only on the override',
    ]);
    ContentAccessOverride::query()->create([
        'actor_id' => (string) $admin->getKey(),
        'access' => ContentAccess::Denied,
        'set_by_actor_id' => (string) $owner->getKey(),
        'set_at' => now(),
    ]);
    ContentAccessOverride::query()->create([
        'actor_id' => (string) $owner->getKey(),
        'access' => ContentAccess::Denied,
        'set_by_actor_id' => (string) $owner->getKey(),
        'set_at' => now(),
    ]);

    expect($policy->project($member)->content)->toBeTrue()
        ->and($policy->project($admin)->content)->toBeFalse()
        ->and($policy->project($owner)->content)->toBeTrue()
        ->and($policy->project($owner)->source)->toBe(ContentAccessSource::Owner);

    $delegated = DelegatedActor::query()->create([
        'identity_hash' => DelegatedActor::identityHash('issuer', 'operator'),
        'issuer' => 'issuer',
        'subject' => 'operator',
        'last_handoff_display_name' => 'Operator',
        'last_handoff_role' => ConsoleRole::Admin,
    ]);
    $delegatedAccess = $policy->decide(
        ActingPrincipal::delegatedRequest(
            $delegated,
            new DelegatedClaims('Operator', ConsoleRole::Admin, 'Agency'),
        ),
        null,
    );

    expect([$delegatedAccess->usage, $delegatedAccess->content])->toBe([true, true]);
});

it('enforces owner admin member management authority and leaves forbidden writes inert', function (): void {
    $owner = createAssayUser('Matrix Owner', UserRole::Owner);
    $admin = createAssayUser('Matrix Admin', UserRole::Admin);
    $otherAdmin = createAssayUser('Matrix Other Admin', UserRole::Admin);
    $member = createAssayUser('Matrix Member', UserRole::Member);

    $this->actingAs($owner)->putJson(route('assay.access.set', $otherAdmin), ['access' => 'denied'])
        ->assertOk()->assertJson(['changed' => true]);
    $this->actingAs($owner)->putJson(route('assay.access.set', $member), ['access' => 'granted'])
        ->assertOk()->assertJson(['changed' => true]);
    $this->actingAs($admin)->putJson(route('assay.access.set', $member), ['access' => 'denied'])
        ->assertOk()->assertJson(['changed' => true]);

    foreach ([
        [$admin, $otherAdmin, 'denied'],
        [$member, $otherAdmin, 'denied'],
        [$member, $member, 'granted'],
        [$owner, $owner, 'denied'],
    ] as [$actor, $target, $access]) {
        $beforeOverrides = ContentAccessOverride::query()->get()->map->getAttributes()->all();
        $beforeAudits = AppActionEvent::query()->count();

        $this->actingAs($actor)->putJson(route('assay.access.set', $target), [
            'access' => $access,
            'role' => 'member',
        ])
            ->assertForbidden();

        expect(ContentAccessOverride::query()->get()->map->getAttributes()->all())->toBe($beforeOverrides)
            ->and(AppActionEvent::query()->count())->toBe($beforeAudits);
    }

    $this->actingAs($owner)->putJson(route('assay.access.set', $admin), ['access' => 'denied'])
        ->assertOk();
    $beforeOverrides = ContentAccessOverride::query()->get()->map->getAttributes()->all();
    $beforeAudits = AppActionEvent::query()->count();

    $this->actingAs($admin)->putJson(route('assay.access.set', $member), ['access' => 'granted'])
        ->assertForbidden();

    expect(ContentAccessOverride::query()->get()->map->getAttributes()->all())->toBe($beforeOverrides)
        ->and(AppActionEvent::query()->count())->toBe($beforeAudits);
});

it('persists overrides through role and admission changes and deletes defaults and resets without no-op audits', function (): void {
    $owner = createAssayUser('Lifecycle Owner', UserRole::Owner);
    $member = createAssayUser('Lifecycle Member', UserRole::Member);
    $policy = resolve(AssayAccessPolicy::class);

    $this->actingAs($owner)->putJson(route('assay.access.set', $member), [
        'access' => 'granted',
        'reason' => 'bounded reason',
    ])->assertOk()->assertJson(['changed' => true]);

    $member->forceFill(['role' => UserRole::Admin->value])->save();
    expect($policy->project($member)->content)->toBeTrue()
        ->and($policy->project($member)->redundant)->toBeTrue();

    $member->forceFill(['status' => 'inactive'])->save();
    expect([$policy->project($member)->usage, $policy->project($member)->content])->toBe([false, false])
        ->and(ContentAccessOverride::query()->whereKey($member->getKey())->exists())->toBeTrue();

    $member->forceFill(['status' => 'active'])->save();
    $this->actingAs($owner)->putJson(route('assay.access.set', $member), ['access' => 'granted'])
        ->assertOk()->assertJson(['changed' => true]);
    expect(ContentAccessOverride::query()->whereKey($member->getKey())->exists())->toBeFalse();

    $this->actingAs($owner)->putJson(route('assay.access.set', $member), ['access' => 'denied'])
        ->assertOk()->assertJson(['changed' => true]);
    $this->actingAs($owner)->deleteJson(route('assay.access.reset', $member))
        ->assertOk()->assertJson(['changed' => true]);
    $this->actingAs($owner)->deleteJson(route('assay.access.reset', $member))
        ->assertOk()->assertJson(['changed' => false]);

    $events = AppActionEvent::query()->oldest('occurred_at')->get();
    expect($events)->toHaveCount(4)
        ->and($events->pluck('actor_ref')->unique()->values()->all())->toBe([(string) $owner->getKey()])
        ->and($events->pluck('action')->all())->toBe([
            AppAction::ContentAccessOverrideSet->value,
            AppAction::ContentAccessOverrideReset->value,
            AppAction::ContentAccessOverrideSet->value,
            AppAction::ContentAccessOverrideReset->value,
        ])
        ->and($events->pluck('action_vocabulary')->unique()->values()->all())->toBe([AppAction::class]);
    expect(json_encode($events->map->getAttributes()->all(), JSON_THROW_ON_ERROR))->not->toContain('bounded reason');
});

it('rolls back the override when the app-action recorder throws', function (): void {
    $owner = createAssayUser('Rollback Owner', UserRole::Owner);
    $member = createAssayUser('Rollback Member', UserRole::Member);

    Event::listen(QueryExecuted::class, static function (QueryExecuted $event): void {
        if (str_starts_with(strtolower($event->sql), 'insert into "bfc_app_action_events"')) {
            throw new RuntimeException('audit unavailable');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($owner)->putJson(route('assay.access.set', $member), [
        'access' => 'granted',
    ]))->toThrow(RuntimeException::class, 'audit unavailable');

    expect(ContentAccessOverride::query()->count())->toBe(0)
        ->and(AppActionEvent::query()->count())->toBe(0);
});

it('rechecks the acting person inside the mutation transaction', function (): void {
    $admin = createAssayUser('Stale Decision Admin', UserRole::Admin);
    $member = createAssayUser('Stale Decision Member', UserRole::Member);
    $principal = ActingPrincipal::local('web', $admin);

    DB::table('users')->where('id', $admin->getKey())->update(['role' => UserRole::Member->value]);

    expect(fn () => resolve(ContentAccessOverrideService::class)->set(
        $principal,
        $member->getKey(),
        ContentAccess::Granted,
    ))->toThrow(AuthorizationException::class);

    expect(ContentAccessOverride::query()->count())->toBe(0)
        ->and(AppActionEvent::query()->count())->toBe(0);
});

it('maps every explicit PR5 route to the central ability middleware', function (): void {
    $expected = [
        'assay.dashboard' => 'assay.access:usage',
        'assay.dashboard.usage-over-time' => 'assay.access:usage',
        'assay.dashboard.usage-over-time.csv' => 'assay.access:usage',
        'assay.dashboard.usage-by-agent' => 'assay.access:usage',
        'assay.dashboard.usage-by-agent.csv' => 'assay.access:usage',
        'assay.dashboard.usage-by-subject' => 'assay.access:usage',
        'assay.dashboard.usage-by-subject.csv' => 'assay.access:usage',
        'assay.dashboard.top-runs' => 'assay.access:usage',
        'assay.dashboard.top-runs.csv' => 'assay.access:usage',
        'assay.dashboard.reliability' => 'assay.access:usage',
        'assay.dashboard.reliability.csv' => 'assay.access:usage',
        'assay.dashboard.latency' => 'assay.access:usage',
        'assay.dashboard.latency.csv' => 'assay.access:usage',
        'assay.dashboard.pipeline-health' => 'assay.access:usage',
        'assay.dashboard.pipeline-health.csv' => 'assay.access:usage',
        'assay.risk' => 'assay.access:usage',
        'assay.runs.tree' => 'assay.access:content',
        'assay.access.index' => 'assay.access:content',
        'assay.access.set' => 'assay.access:content',
        'assay.access.reset' => 'assay.access:content',
    ];

    foreach ($expected as $name => $middleware) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull()
            ->and($route?->gatherMiddleware())->toContain('web', $middleware);
    }

    expect(Route::getMiddlewareGroups())->not->toBe([])
        ->and(resolve('router')->getMiddleware()['assay.access'] ?? null)->toBe(EnsureAssayAccess::class);
});

it('denies every PR5 read to active ingest credentials and treats unknown or revoked bearers as unauthenticated', function (): void {
    $run = (string) Str::uuid();
    $ingest = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'read-denied-app',
    ]);
    $revoked = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'revoked-read-app',
        'revoked_at' => now(),
    ]);
    $otherValid = $this->mintCredential([
        'purpose' => CredentialPurpose::SystemDeployment,
        'subject_type' => SubjectType::Application,
        'subject_ref' => 'other-valid-read-credential',
    ]);

    foreach (pr5ReadUrls($run) as $url) {
        $this->withHeader('Authorization', $ingest->bearerHeader())->getJson($url)->assertForbidden();
        $this->withToken('unknown-read-secret')->getJson($url)->assertUnauthorized();
        $this->withHeader('Authorization', $revoked->bearerHeader())->getJson($url)->assertUnauthorized();
    }

    $this->withHeader('Authorization', $otherValid->bearerHeader())
        ->getJson(route('assay.dashboard.usage-over-time'))
        ->assertForbidden();

    $target = createAssayUser('Credential Write Target', UserRole::Member);
    $this->withHeader('Authorization', $ingest->bearerHeader())
        ->putJson(route('assay.access.set', $target), ['access' => 'granted'])
        ->assertForbidden();
    $this->withHeader('Authorization', $ingest->bearerHeader())
        ->deleteJson(route('assay.access.reset', $target))
        ->assertForbidden();
    expect(ContentAccessOverride::query()->count())->toBe(0);
});

it('keeps usage-only responses link-free and denies direct run-tree access across representations', function (): void {
    $member = createAssayUser('Usage Only Member', UserRole::Member);
    $owner = createAssayUser('Link Owner', UserRole::Owner);
    $run = seedLinkedRun();

    $this->actingAs($member)->get(route('assay.dashboard'))
        ->assertOk()
        ->assertSee('data-testid="dashboard"', false)
        ->assertDontSee(route('assay.runs.tree', ['run' => $run], false));
    $this->actingAs($member)->getJson(route('assay.dashboard.top-runs'))
        ->assertOk()
        ->assertJsonMissing(['run_tree_id' => $run]);
    $this->actingAs($member)->get(route('assay.dashboard.top-runs.csv'))
        ->assertOk()
        ->assertDontSee('run_tree_id')
        ->assertDontSee(route('assay.runs.tree', ['run' => $run], false));
    $this->actingAs($member)->getJson(route('assay.runs.tree', ['run' => $run]))->assertForbidden();

    $this->actingAs($owner)->get(route('assay.dashboard'))
        ->assertOk()
        ->assertSee(route('assay.runs.tree', ['run' => $run], false));
    $this->actingAs($owner)->getJson(route('assay.runs.tree', ['run' => $run]))
        ->assertOk()
        ->assertJsonPath('rows.0.run_id', $run);
});

it('renders structural dashboard and access sections without reasons secrets content or money vocabulary', function (): void {
    $owner = createAssayUser('Surface Owner', UserRole::Owner);
    $member = createAssayUser('Surface Member', UserRole::Member);
    ContentAccessOverride::query()->create([
        'actor_id' => (string) $member->getKey(),
        'access' => ContentAccess::Granted,
        'set_by_actor_id' => (string) $owner->getKey(),
        'set_at' => now(),
        'reason' => 'REASON-CANARY',
    ]);

    $dashboard = $this->actingAs($owner)->get(route('assay.dashboard'))->assertOk();
    $dashboard->assertSee('data-testid="dashboard"', false)
        ->assertSee('data-testid="dashboard-metric-selector"', false);

    foreach (['usage-over-time', 'usage-by-agent', 'usage-by-subject', 'top-runs', 'reliability', 'latency', 'pipeline-health'] as $section) {
        $dashboard->assertSee('data-testid="dashboard-'.$section.'"', false);
    }

    $access = $this->actingAs($owner)->get(route('assay.access.index'))
        ->assertOk()
        ->assertSee('data-testid="access-management"', false)
        ->assertSee('Surface Member')
        ->assertSee(route('assay.access.set', ['actor' => $member->getKey()], false))
        ->assertSee(route('assay.access.reset', ['actor' => $member->getKey()], false))
        ->assertDontSee('REASON-CANARY');
    $body = strtolower((string) $dashboard->getContent().$access->getContent());

    expect($body)->not->toMatch('/\b(price|cost|currency|budget)\b|[$€]/')
        ->and($body)->not->toContain('prompt', 'response body', 'tool arguments', 'credential value');
});

it('covers the PR5 people surface matrix while usage/content credentials and person-bound caps remain deferred to PR7b', function (): void {
    // Usage-scoped credentials, content-scoped credentials, and person-bound credential caps are PR7b, not PR5.
    $owner = createAssayUser('Surface Matrix Owner', UserRole::Owner);
    $admin = createAssayUser('Surface Matrix Admin', UserRole::Admin);
    $member = createAssayUser('Surface Matrix Member', UserRole::Member);
    $narrowedAdmin = createAssayUser('Surface Matrix Narrow Admin', UserRole::Admin);
    $overriddenMember = createAssayUser('Surface Matrix Override Member', UserRole::Member);

    foreach ([
        [$narrowedAdmin, ContentAccess::Denied],
        [$overriddenMember, ContentAccess::Granted],
    ] as [$user, $access]) {
        ContentAccessOverride::query()->create([
            'actor_id' => (string) $user->getKey(),
            'access' => $access,
            'set_by_actor_id' => (string) $owner->getKey(),
            'set_at' => now(),
        ]);
    }

    foreach ([$owner, $admin, $member, $narrowedAdmin, $overriddenMember] as $actor) {
        foreach (pr5UsageUrls() as $url) {
            $this->actingAs($actor)->get($url)->assertOk();
        }
    }

    $missingRun = (string) Str::uuid();

    foreach ([$owner, $admin] as $actor) {
        $this->actingAs($actor)->get(route('assay.access.index'))->assertOk();
    }

    foreach ([$owner, $admin, $overriddenMember] as $actor) {
        $this->actingAs($actor)->getJson(route('assay.runs.tree', $missingRun))->assertNotFound();
    }

    foreach ([$member, $narrowedAdmin, $overriddenMember] as $actor) {
        $this->actingAs($actor)->getJson(route('assay.access.index'))->assertForbidden();
    }

    foreach ([$member, $narrowedAdmin] as $actor) {
        $this->actingAs($actor)->getJson(route('assay.runs.tree', $missingRun))->assertForbidden();
    }

    $writeActors = [
        [$owner, true],
        [$admin, true],
        [$member, false],
        [$narrowedAdmin, false],
        [$overriddenMember, false],
    ];

    foreach ($writeActors as [$actor, $allowed]) {
        $target = createAssayUser('Write Target '.$actor->getKey(), UserRole::Member);
        $set = $this->actingAs($actor)->putJson(route('assay.access.set', $target), ['access' => 'granted']);

        if ($allowed) {
            $set->assertOk();
            $this->actingAs($actor)->deleteJson(route('assay.access.reset', $target))->assertOk();
        } else {
            $set->assertForbidden();
            $this->actingAs($actor)->deleteJson(route('assay.access.reset', $target))->assertForbidden();
        }
    }

});
