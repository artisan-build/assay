<?php

declare(strict_types=1);

use App\Enums\ContentAccess;
use App\Jobs\ProcessUsageEnvelope;
use App\Models\ContentAccessOverride;
use App\Services\UsageIngestProcessor;
use ArtisanBuild\AssayContracts\UuidV7;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnvelopeFactory;

function fullCaptureUser(string $name, UserRole $role): User
{
    $user = User::query()->create([
        'name' => $name,
        'email' => str($name)->slug().'@example.test',
    ]);
    $user->forceFill(['role' => $role->value])->save();

    return $user;
}

it('stores full content, never stores usage content, and resolves late message bodies idempotently', function (): void {
    $processor = resolve(UsageIngestProcessor::class);
    $hash = hash('sha256', '{"role":"user","text":"MESSAGE-CANARY"}');
    $recordId = (string) UuidV7::generate();
    $reference = EnvelopeFactory::record([
        'record_id' => $recordId,
        'type' => 'step.start',
        'capture' => 'full',
        'invocation_id' => 'full-run',
        'step' => 0,
        'content' => ['message_hashes' => [$hash]],
    ]);
    $runStart = EnvelopeFactory::record([
        'capture' => 'full',
        'invocation_id' => 'full-run',
        'content' => ['instructions' => 'INSTRUCTION-CANARY'],
    ]);
    ProcessUsageEnvelope::fromContract(
        'full-app',
        '2026-10-01T12:00:00.000000+00:00',
        EnvelopeFactory::envelope([$reference, $runStart]),
    )->handle($processor);

    $runId = (string) DB::table('assay_runs')->where('invocation_id', 'full-run')->value('id');
    expect(DB::table('assay_record_content')->count())->toBe(2)
        ->and(DB::table('assay_messages')->count())->toBe(0)
        ->and(DB::table('assay_message_references')->count())->toBe(1)
        ->and(DB::table('assay_runs')->where('id', $runId)->value('content_incomplete'))->toBeTrue();

    $lateBody = EnvelopeFactory::record([
        'record_id' => $recordId,
        'type' => 'step.start',
        'capture' => 'full',
        'invocation_id' => 'full-run',
        'step' => 0,
        'content' => [
            'message_hashes' => [$hash],
            'new_messages' => [$hash => ['role' => 'user', 'text' => 'MESSAGE-CANARY']],
        ],
    ]);
    ProcessUsageEnvelope::fromContract(
        'full-app',
        '2026-10-01T12:01:00.000000+00:00',
        EnvelopeFactory::envelope([$lateBody]),
    )->handle($processor);
    ProcessUsageEnvelope::fromContract(
        'full-app',
        '2026-10-01T12:02:00.000000+00:00',
        EnvelopeFactory::envelope([$lateBody]),
    )->handle($processor);

    expect(DB::table('assay_records')->count())->toBe(2)
        ->and(DB::table('assay_messages')->count())->toBe(1)
        ->and(DB::table('assay_message_references')->count())->toBe(1)
        ->and(DB::table('assay_runs')->where('id', $runId)->value('content_incomplete'))->toBeFalse();

    ProcessUsageEnvelope::fromContract(
        'usage-app',
        '2026-10-01T12:03:00.000000+00:00',
        EnvelopeFactory::envelope([EnvelopeFactory::record(['invocation_id' => 'usage-run'])]),
    )->handle($processor);

    expect(DB::table('assay_record_content')->whereIn('run_id', DB::table('assay_runs')->where('invocation_id', 'usage-run')->select('id'))->count())->toBe(0);
});

it('exposes content only to independently authorized people and never through usage surfaces', function (): void {
    $owner = fullCaptureUser('Full Owner', UserRole::Owner);
    $admin = fullCaptureUser('Narrow Admin', UserRole::Admin);
    $member = fullCaptureUser('Usage Member', UserRole::Member);
    $granted = fullCaptureUser('Granted Member', UserRole::Member);
    ContentAccessOverride::query()->create([
        'actor_id' => (string) $admin->getKey(),
        'access' => ContentAccess::Denied,
        'set_by_actor_id' => (string) $owner->getKey(),
        'set_at' => now(),
    ]);
    ContentAccessOverride::query()->create([
        'actor_id' => (string) $granted->getKey(),
        'access' => ContentAccess::Granted,
        'set_by_actor_id' => (string) $owner->getKey(),
        'set_at' => now(),
    ]);
    ProcessUsageEnvelope::fromContract(
        'auth-app',
        '2026-10-01T12:00:00.000000+00:00',
        EnvelopeFactory::envelope([EnvelopeFactory::record([
            'capture' => 'full',
            'invocation_id' => 'auth-run',
            'content' => ['instructions' => 'AUTH-CONTENT-CANARY'],
        ])]),
    )->handle(resolve(UsageIngestProcessor::class));
    $runId = (string) DB::table('assay_runs')->where('invocation_id', 'auth-run')->value('id');

    foreach ([$owner, $granted] as $actor) {
        $this->actingAs($actor)->getJson(route('assay.runs.tree', $runId))
            ->assertOk()
            ->assertSee('AUTH-CONTENT-CANARY');
    }

    foreach ([$admin, $member] as $actor) {
        $this->actingAs($actor)->getJson(route('assay.runs.tree', $runId))->assertForbidden();
        $this->actingAs($actor)->get(route('assay.dashboard'))->assertOk()->assertDontSee('AUTH-CONTENT-CANARY');
        $this->actingAs($actor)->getJson(route('assay.dashboard.top-runs'))->assertOk()->assertDontSee('AUTH-CONTENT-CANARY');
        $this->actingAs($actor)->get(route('assay.dashboard.top-runs.csv'))->assertOk()->assertDontSee('AUTH-CONTENT-CANARY');
    }
});
