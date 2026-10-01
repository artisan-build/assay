<?php

declare(strict_types=1);

use App\Enums\ContentAccess;
use App\Jobs\ProcessUsageEnvelope;
use App\Models\ContentAccessOverride;
use App\Services\UsageIngestProcessor;
use ArtisanBuild\AssayContracts\UuidV7;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        'record_id' => (string) UuidV7::generate(),
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

    expect(DB::table('assay_records')->count())->toBe(3)
        ->and(DB::table('assay_messages')->count())->toBe(1)
        ->and(DB::table('assay_message_references')->count())->toBe(2)
        ->and(DB::table('assay_runs')->where('id', $runId)->value('content_incomplete'))->toBeFalse();

    ProcessUsageEnvelope::fromContract(
        'usage-app',
        '2026-10-01T12:03:00.000000+00:00',
        EnvelopeFactory::envelope([EnvelopeFactory::record(['invocation_id' => 'usage-run'])]),
    )->handle($processor);

    expect(DB::table('assay_record_content')->whereIn('run_id', DB::table('assay_runs')->where('invocation_id', 'usage-run')->select('id'))->count())->toBe(0);
});

it('round-trips every frozen content shape through PostgreSQL and the run tree', function (): void {
    $message = ['role' => 'user', 'text' => 'MESSAGE-MATRIX-CANARY'];
    $hash = hash('sha256', json_encode($message, JSON_THROW_ON_ERROR));
    $structuredRecordId = (string) UuidV7::generate();
    $records = [
        EnvelopeFactory::record(['capture' => 'full', 'invocation_id' => 'matrix-agent', 'content' => [
            'instructions' => 'INSTRUCTIONS-MATRIX-CANARY',
            'tools' => [['name' => 'lookup', 'description' => 'Lookup', 'parameters' => ['type' => 'object']]],
        ]]),
        EnvelopeFactory::record(['type' => 'step.start', 'capture' => 'full', 'invocation_id' => 'matrix-agent', 'step' => 0, 'content' => [
            'message_hashes' => [$hash],
            'new_messages' => [$hash => $message],
        ]]),
        EnvelopeFactory::record(['record_id' => $structuredRecordId, 'type' => 'step.end', 'capture' => 'full', 'invocation_id' => 'matrix-agent', 'step' => 0, 'content' => [
            'output_text' => 'OUTPUT-MATRIX-CANARY',
            'structured_output' => [
                'structured' => 'STRUCTURED-MATRIX-CANARY',
                'empty_object' => (object) [],
                'empty_list' => [],
            ],
            'tool_calls' => [['id' => 'call-1', 'name' => 'lookup', 'arguments' => ['id' => 1]]],
        ]]),
        EnvelopeFactory::record(['type' => 'tool.start', 'capture' => 'full', 'invocation_id' => 'matrix-agent', 'tool_invocation_id' => 'tool-1', 'content' => ['arguments' => ['customer' => 'ARGUMENT-MATRIX-CANARY']]]),
        EnvelopeFactory::record(['type' => 'tool.end', 'capture' => 'full', 'invocation_id' => 'matrix-agent', 'tool_invocation_id' => 'tool-1', 'outcome' => 'completed', 'content' => ['result' => ['value' => 'RESULT-MATRIX-CANARY']]]),
        EnvelopeFactory::record(['type' => 'tool.end', 'capture' => 'full', 'invocation_id' => 'matrix-agent', 'tool_invocation_id' => 'tool-2', 'outcome' => 'failed', 'content' => ['exception_message' => 'TOOL-EXCEPTION-MATRIX-CANARY']]),
        EnvelopeFactory::record(['type' => 'step.fail', 'capture' => 'full', 'invocation_id' => 'matrix-agent', 'step' => 1, 'content' => ['exception_message' => 'STEP-EXCEPTION-MATRIX-CANARY']]),
        EnvelopeFactory::record(['type' => 'run.end', 'capture' => 'full', 'invocation_id' => 'matrix-agent', 'outcome' => 'failed', 'content' => ['exception_message' => 'RUN-EXCEPTION-MATRIX-CANARY']]),
        EnvelopeFactory::record(['operation' => 'embeddings', 'attempt' => null, 'capture' => 'full', 'invocation_id' => 'matrix-embeddings', 'content' => ['inputs' => ['EMBEDDINGS-MATRIX-CANARY']]]),
        EnvelopeFactory::record(['operation' => 'image', 'attempt' => null, 'capture' => 'full', 'invocation_id' => 'matrix-image', 'content' => ['prompt' => 'IMAGE-MATRIX-CANARY']]),
        EnvelopeFactory::record(['operation' => 'audio', 'attempt' => null, 'capture' => 'full', 'invocation_id' => 'matrix-audio', 'content' => ['text' => 'AUDIO-MATRIX-CANARY']]),
        EnvelopeFactory::record(['operation' => 'reranking', 'attempt' => null, 'capture' => 'full', 'invocation_id' => 'matrix-reranking', 'content' => ['query' => 'QUERY-MATRIX-CANARY', 'documents' => ['DOCUMENT-MATRIX-CANARY']]]),
        EnvelopeFactory::record(['operation' => 'classification', 'attempt' => null, 'capture' => 'full', 'invocation_id' => 'matrix-classification', 'content' => ['prompt' => 'CLASSIFICATION-MATRIX-CANARY', 'labels' => ['LABEL-MATRIX-CANARY']]]),
        EnvelopeFactory::record(['type' => 'run.end', 'operation' => 'image', 'attempt' => null, 'capture' => 'full', 'invocation_id' => 'matrix-image', 'outcome' => 'completed', 'content' => ['count' => 1, 'dimensions' => [['width' => 640, 'height' => 480]]]]),
        EnvelopeFactory::record(['type' => 'run.end', 'operation' => 'transcription', 'attempt' => null, 'capture' => 'full', 'invocation_id' => 'matrix-transcription', 'outcome' => 'completed', 'content' => ['text' => 'TRANSCRIPTION-MATRIX-CANARY']]),
        EnvelopeFactory::record(['type' => 'run.end', 'operation' => 'reranking', 'attempt' => null, 'capture' => 'full', 'invocation_id' => 'matrix-reranking', 'outcome' => 'completed', 'content' => ['results' => [['index' => 0, 'score' => 0.75]]]]),
        EnvelopeFactory::record(['type' => 'run.end', 'operation' => 'classification', 'attempt' => null, 'capture' => 'full', 'invocation_id' => 'matrix-classification', 'outcome' => 'completed', 'content' => ['answers' => ['answer' => 'ANSWER-MATRIX-CANARY']]]),
    ];

    ProcessUsageEnvelope::fromContract(
        'matrix-app',
        '2026-10-01T12:00:00.000000+00:00',
        EnvelopeFactory::envelope($records),
    )->handle(resolve(UsageIngestProcessor::class));

    expect(DB::table('assay_record_content')->count())->toBe(17)
        ->and(DB::table('assay_messages')->count())->toBe(1)
        ->and(json_encode(DB::table('assay_record_content')->pluck('content'), JSON_THROW_ON_ERROR))
        ->toContain(
            'INSTRUCTIONS-MATRIX-CANARY',
            'OUTPUT-MATRIX-CANARY',
            'ARGUMENT-MATRIX-CANARY',
            'RESULT-MATRIX-CANARY',
            'EMBEDDINGS-MATRIX-CANARY',
            'IMAGE-MATRIX-CANARY',
            'AUDIO-MATRIX-CANARY',
            'QUERY-MATRIX-CANARY',
            'CLASSIFICATION-MATRIX-CANARY',
            'TRANSCRIPTION-MATRIX-CANARY',
            'ANSWER-MATRIX-CANARY',
        )
        ->and(json_encode(DB::table('assay_messages')->pluck('body'), JSON_THROW_ON_ERROR))->toContain('MESSAGE-MATRIX-CANARY');

    $stored = json_decode((string) DB::table('assay_record_content')
        ->join('assay_records', 'assay_records.id', '=', 'assay_record_content.record_id')
        ->where('assay_records.record_id', $structuredRecordId)
        ->value('content'), false, flags: JSON_THROW_ON_ERROR);

    expect($stored->structured_output->empty_object)->toBeInstanceOf(stdClass::class)
        ->and($stored->structured_output->empty_list)->toBe([]);

    $owner = fullCaptureUser('Matrix Content Owner', UserRole::Owner);
    $runId = (string) DB::table('assay_runs')->where('invocation_id', 'matrix-agent')->value('id');
    $this->actingAs($owner)->getJson(route('assay.runs.tree', $runId))
        ->assertOk()
        ->assertSee('INSTRUCTIONS-MATRIX-CANARY')
        ->assertSee('MESSAGE-MATRIX-CANARY')
        ->assertSee('RUN-EXCEPTION-MATRIX-CANARY');
});

it('treats same-run duplicate record ids as immutable', function (): void {
    $recordId = (string) UuidV7::generate();
    $processor = resolve(UsageIngestProcessor::class);
    $original = EnvelopeFactory::record([
        'record_id' => $recordId,
        'capture' => 'full',
        'invocation_id' => 'immutable-run',
        'content' => ['instructions' => 'ORIGINAL-CONTENT'],
    ]);
    $duplicate = EnvelopeFactory::record([
        'record_id' => $recordId,
        'capture' => 'full',
        'invocation_id' => 'immutable-run',
        'content' => ['instructions' => 'REPLACEMENT-CONTENT'],
    ]);

    ProcessUsageEnvelope::fromContract(
        'immutable-app',
        '2026-10-01T12:00:00.000000+00:00',
        EnvelopeFactory::envelope([$original]),
    )->handle($processor);
    ProcessUsageEnvelope::fromContract(
        'immutable-app',
        '2026-10-01T12:01:00.000000+00:00',
        EnvelopeFactory::envelope([$duplicate]),
    )->handle($processor);

    $stored = (string) DB::table('assay_record_content')->sole()->content;

    expect(DB::table('assay_records')->count())->toBe(1)
        ->and(DB::table('assay_record_content')->count())->toBe(1)
        ->and($stored)->toContain('ORIGINAL-CONTENT')
        ->not->toContain('REPLACEMENT-CONTENT');
});

it('does not cross-attach a duplicate record to a different run', function (): void {
    $recordId = (string) UuidV7::generate();
    $processor = resolve(UsageIngestProcessor::class);
    $original = EnvelopeFactory::record([
        'record_id' => $recordId,
        'type' => 'step.end',
        'invocation_id' => 'run-a',
        'step' => 0,
    ]);
    $runB = EnvelopeFactory::record(['invocation_id' => 'run-b']);
    $duplicate = EnvelopeFactory::record([
        'record_id' => $recordId,
        'type' => 'step.end',
        'capture' => 'full',
        'invocation_id' => 'run-b',
        'step' => 0,
        'content' => ['output_text' => 'CROSS-RUN-CANARY'],
    ]);

    foreach ([[$original], [$runB], [$duplicate]] as $offset => $records) {
        ProcessUsageEnvelope::fromContract(
            'cross-run-app',
            sprintf('2026-10-01T12:0%d:00.000000+00:00', $offset),
            EnvelopeFactory::envelope($records),
        )->handle($processor);
    }

    $stepRun = DB::table('assay_steps')
        ->join('assay_runs', 'assay_runs.id', '=', 'assay_steps.run_id')
        ->value('assay_runs.invocation_id');

    expect(DB::table('assay_records')->count())->toBe(2)
        ->and(DB::table('assay_steps')->count())->toBe(1)
        ->and($stepRun)->toBe('run-a')
        ->and(DB::table('assay_record_content')->count())->toBe(0)
        ->and(json_encode(DB::table('assay_runs')->get(), JSON_THROW_ON_ERROR))->not->toContain('CROSS-RUN-CANARY');
});

it('rolls back app metadata usage and content when content storage fails', function (): void {
    Event::listen(QueryExecuted::class, static function (QueryExecuted $event): void {
        if (str_starts_with(strtolower($event->sql), 'insert into "assay_record_content"')) {
            throw new RuntimeException('content storage unavailable');
        }
    });
    $record = EnvelopeFactory::record([
        'type' => 'step.end',
        'capture' => 'full',
        'step' => 0,
        'usage' => ['input_tokens' => 3],
        'content' => ['output_text' => 'ROLLBACK-CONTENT-CANARY'],
    ]);

    expect(fn () => ProcessUsageEnvelope::fromContract(
        'rollback-app',
        '2026-10-01T12:00:00.000000+00:00',
        EnvelopeFactory::envelope([$record]),
    )->handle(resolve(UsageIngestProcessor::class)))->toThrow(RuntimeException::class, 'content storage unavailable');

    expect(DB::table('assay_apps')->count())->toBe(0)
        ->and(DB::table('assay_envelopes')->count())->toBe(0)
        ->and(DB::table('assay_records')->count())->toBe(0)
        ->and(DB::table('assay_runs')->count())->toBe(0)
        ->and(DB::table('assay_usage_metrics')->count())->toBe(0)
        ->and(DB::table('assay_record_content')->count())->toBe(0);
});

it('exposes content only to independently authorized people and never through usage surfaces', function (): void {
    $owner = fullCaptureUser('Full Owner', UserRole::Owner);
    $admin = fullCaptureUser('Full Admin', UserRole::Admin);
    $deniedAdmin = fullCaptureUser('Narrow Admin', UserRole::Admin);
    $member = fullCaptureUser('Usage Member', UserRole::Member);
    $granted = fullCaptureUser('Granted Member', UserRole::Member);
    ContentAccessOverride::query()->create([
        'actor_id' => (string) $deniedAdmin->getKey(),
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

    foreach ([$owner, $admin, $granted] as $actor) {
        $this->actingAs($actor)->getJson(route('assay.runs.tree', $runId))
            ->assertOk()
            ->assertSee('AUTH-CONTENT-CANARY');
        $this->actingAs($actor)->get(route('assay.dashboard'))->assertOk()->assertDontSee('AUTH-CONTENT-CANARY');
        $this->actingAs($actor)->getJson(route('assay.dashboard.top-runs'))->assertOk()->assertDontSee('AUTH-CONTENT-CANARY');
        $this->actingAs($actor)->get(route('assay.dashboard.top-runs.csv'))->assertOk()->assertDontSee('AUTH-CONTENT-CANARY');
    }

    foreach ([$deniedAdmin, $member] as $actor) {
        $this->actingAs($actor)->getJson(route('assay.runs.tree', $runId))->assertForbidden();
        $this->actingAs($actor)->get(route('assay.dashboard'))->assertOk()->assertDontSee('AUTH-CONTENT-CANARY');
        $this->actingAs($actor)->getJson(route('assay.dashboard.top-runs'))->assertOk()->assertDontSee('AUTH-CONTENT-CANARY');
        $this->actingAs($actor)->get(route('assay.dashboard.top-runs.csv'))->assertOk()->assertDontSee('AUTH-CONTENT-CANARY');
    }
});
