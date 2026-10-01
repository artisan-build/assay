<?php

declare(strict_types=1);

use App\Jobs\ProcessUsageEnvelope;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\Source;
use ArtisanBuild\AssayContracts\UuidV7;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Support\EnvelopeFactory;

uses(WithCredentials::class);

it('registers machine routes outside the web middleware group', function (): void {
    $ingest = Route::getRoutes()->match(Request::create('/ingest', 'POST'));
    $capabilities = Route::getRoutes()->match(Request::create('/capabilities', 'GET'));

    expect($ingest->middleware())->not->toContain('web')
        ->and($capabilities->middleware())->not->toContain('web')
        ->and($ingest->excludedMiddleware())->toBe([])
        ->and($capabilities->excludedMiddleware())->toBe([]);
});

it('accepts only an active installation-owned ingest credential and derives app identity from it', function (): void {
    Bus::fake();
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'credential-app',
    ]);
    $envelope = EnvelopeFactory::envelope([
        EnvelopeFactory::record([
            'capture' => 'full',
            'content' => ['instructions' => 'ALLOWED-CONTENT'],
        ]),
    ]);
    $body = json_decode(EnvelopeCodec::encode($envelope), true, flags: JSON_THROW_ON_ERROR);
    $body['app_id'] = 'forged-app';

    $this->call('POST', '/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $credential->bearerHeader(),
    ], json_encode($body, JSON_THROW_ON_ERROR))->assertAccepted();

    Bus::assertDispatched(ProcessUsageEnvelope::class, function (ProcessUsageEnvelope $job): bool {
        $serialized = serialize($job);

        return $job->appRef === 'credential-app'
            && str_contains($serialized, 'ALLOWED-CONTENT')
            && ! str_contains($serialized, 'forged-app')
            && ! str_contains($serialized, Request::class)
            && ! str_contains($serialized, Credential::class)
            && $job->envelope['records'][0]['content'] === '{"instructions":"ALLOWED-CONTENT"}';
    });
    expect($credential->credential->refresh()->last_used_at)->not->toBeNull();
});

it('creates no session row or cookie for anonymous or authenticated machine requests', function (): void {
    config()->set('session.driver', 'database');
    Bus::fake();
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'credential-app',
    ]);

    $this->postJson('/ingest', [])
        ->assertUnauthorized()
        ->assertHeaderMissing('Set-Cookie');
    $this->call('POST', '/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $credential->bearerHeader(),
    ], EnvelopeCodec::encode(EnvelopeFactory::envelope([])))
        ->assertAccepted()
        ->assertHeaderMissing('Set-Cookie');

    expect(DB::table('sessions')->count())->toBe(0);
});

it('rejects body overflow by actual bytes before usage writes or dispatch', function (): void {
    config()->set('assay.ingest.max_body_bytes', 32);
    Bus::fake();
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'credential-app',
    ]);

    $response = $this->call('POST', '/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => '',
        'HTTP_AUTHORIZATION' => $credential->bearerHeader(),
    ], str_repeat('x', 33));

    $response->assertStatus(413)
        ->assertContent('{"error":"payload_too_large","limit_bytes":32}');
    Bus::assertNothingDispatched();
    expect($credential->credential->refresh()->last_used_at)->toBeNull()
        ->and(DB::table('assay_apps')->count())->toBe(0)
        ->and(DB::table('assay_envelopes')->count())->toBe(0);
});

it('rejects decoded cardinality overflow before usage writes or dispatch', function (string $kind): void {
    config()->set('assay.ingest.max_records', 1);
    config()->set('assay.ingest.max_sources', 1);
    Bus::fake();
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'credential-app',
    ]);
    $envelope = EnvelopeFactory::envelope($kind === 'records'
        ? [EnvelopeFactory::record(), EnvelopeFactory::record()]
        : []);
    $body = json_decode(EnvelopeCodec::encode($envelope), true, flags: JSON_THROW_ON_ERROR);

    if ($kind === 'sources') {
        $body['sources'][] = (new Source('other', 'vendor/other', '1.0.0'))->toArray();
    }

    $response = $this->call('POST', '/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $credential->bearerHeader(),
    ], json_encode($body, JSON_THROW_ON_ERROR));

    $response->assertStatus(413)->assertContent($kind === 'records'
        ? '{"error":"too_many_records","limit":1}'
        : '{"error":"too_many_sources","limit":1}');
    Bus::assertNothingDispatched();
    expect($credential->credential->refresh()->last_used_at)->toBeNull()
        ->and(DB::table('assay_apps')->count())->toBe(0);
})->with(['records', 'sources']);

it('rejects raw cardinality overflow before validating an invalid extra member', function (string $kind): void {
    config()->set('assay.ingest.max_records', 1);
    config()->set('assay.ingest.max_sources', 1);
    Bus::fake();
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'credential-app',
    ]);
    $envelope = EnvelopeFactory::envelope($kind === 'records' ? [EnvelopeFactory::record()] : []);
    $body = json_decode(EnvelopeCodec::encode($envelope), true, flags: JSON_THROW_ON_ERROR);
    $body[$kind][] = ['invalid' => 'INVALID-MEMBER-CANARY'];

    $response = $this->call('POST', '/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $credential->bearerHeader(),
    ], json_encode($body, JSON_THROW_ON_ERROR));

    $response->assertStatus(413)->assertContent($kind === 'records'
        ? '{"error":"too_many_records","limit":1}'
        : '{"error":"too_many_sources","limit":1}');
    Bus::assertNothingDispatched();
    expect($credential->credential->refresh()->last_used_at)->toBeNull()
        ->and(DB::table('assay_apps')->count())->toBe(0)
        ->and(DB::table('assay_envelopes')->count())->toBe(0)
        ->and(DB::table('assay_records')->count())->toBe(0);
})->with(['records', 'sources']);

it('rejects the complete credential-negative matrix without dispatching or recording usage', function (string $case): void {
    Bus::fake();
    $attributes = [
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'credential-app',
    ];

    if ($case === 'missing') {
        $this->postJson('/ingest', [])->assertUnauthorized();
        Bus::assertNothingDispatched();

        return;
    }

    if ($case === 'unknown') {
        $this->withToken('unknown-secret')->postJson('/ingest', [])->assertUnauthorized();
        Bus::assertNothingDispatched();

        return;
    }

    if ($case === 'revoked') {
        $attributes['revoked_at'] = now();
    } elseif ($case === 'account-bound') {
        $attributes['user_id'] = User::query()->create([
            'name' => 'Account User',
            'email' => 'account@example.test',
        ])->getKey();
    } elseif ($case === 'wrong-purpose') {
        $attributes['purpose'] = CredentialPurpose::Mcp;
    } elseif ($case === 'wrong-subject') {
        $attributes['subject_type'] = SubjectType::ExternalConsumer;
    }

    $credential = $this->mintCredential($attributes);
    $this->actingAsCredential($credential)
        ->postJson('/ingest', [])
        ->assertUnauthorized();

    Bus::assertNothingDispatched();
    expect($credential->credential->refresh()->last_used_at)->toBeNull();
})->with(['missing', 'unknown', 'revoked', 'account-bound', 'wrong-purpose', 'wrong-subject']);

it('rejects malformed and newer envelopes with bounded canary-free messages', function (string $body, string $expected): void {
    Bus::fake();
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'credential-app',
    ]);

    $response = $this->call('POST', '/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $credential->bearerHeader(),
    ], $body)->assertUnprocessable();

    $response->assertSee($expected)
        ->assertDontSee('BODY-CANARY')
        ->assertDontSee('SECRET-CANARY');
    expect(strlen((string) $response->getContent()))->toBeLessThan(256);
    Bus::assertNothingDispatched();
})->with([
    'malformed' => ['{"BODY-CANARY":"SECRET-CANARY",', 'Envelope JSON is malformed.'],
    'invalid shape' => ['{"envelope_version":1,"BODY-CANARY":"SECRET-CANARY"}', 'Envelope is invalid.'],
    'newer' => ['{"envelope_version":2,"BODY-CANARY":"SECRET-CANARY"}', 'Upgrade your Assay server.'],
]);

it('rejects invalid content before dispatch or writes without logging the request body', function (): void {
    Bus::fake();
    Log::spy();
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'invalid-content-app',
    ]);
    $body = json_decode(EnvelopeCodec::encode(EnvelopeFactory::envelope([
        EnvelopeFactory::record(),
    ])), true, flags: JSON_THROW_ON_ERROR);
    $body['records'][0]['capture'] = 'full';
    $body['records'][0]['content'] = ['unsupported' => 'INVALID-CONTENT-BODY-CANARY'];

    $this->call('POST', '/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $credential->bearerHeader(),
    ], json_encode($body, JSON_THROW_ON_ERROR))
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Envelope is invalid.'])
        ->assertDontSee('INVALID-CONTENT-BODY-CANARY');

    Bus::assertNothingDispatched();
    expect(DB::table('assay_apps')->count())->toBe(0)
        ->and(DB::table('assay_envelopes')->count())->toBe(0)
        ->and(DB::table('assay_records')->count())->toBe(0)
        ->and(DB::table('assay_record_content')->count())->toBe(0);

    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
        Log::shouldNotHaveReceived($level);
    }
});

it('preserves JSON object and list identity through real ingest storage and the run tree', function (): void {
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'identity-route-app',
    ]);
    $structuredRecordId = (string) UuidV7::generate();
    $envelope = EnvelopeFactory::envelope([
        EnvelopeFactory::record([
            'capture' => 'full',
            'invocation_id' => 'identity-route-run',
            'content' => [
                'instructions' => 'identity route',
                'tools' => [[
                    'name' => 'no_parameters',
                    'description' => 'No parameters',
                    'parameters' => (object) [],
                ]],
            ],
        ]),
        EnvelopeFactory::record([
            'type' => 'step.start',
            'capture' => 'full',
            'invocation_id' => 'identity-route-run',
            'step' => 0,
            'content' => ['message_hashes' => [], 'new_messages' => (object) []],
        ]),
        EnvelopeFactory::record([
            'record_id' => $structuredRecordId,
            'type' => 'step.end',
            'capture' => 'full',
            'invocation_id' => 'identity-route-run',
            'step' => 0,
            'content' => [
                'output_text' => 'identity output',
                'structured_output' => (object) [
                    'empty_object' => (object) [],
                    'empty_list' => [],
                    'populated_list' => [(object) ['value' => true]],
                ],
            ],
        ]),
    ]);

    $this->call('POST', '/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $credential->bearerHeader(),
    ], EnvelopeCodec::encode($envelope))->assertAccepted();

    $stored = json_decode((string) DB::table('assay_record_content')
        ->join('assay_records', 'assay_records.id', '=', 'assay_record_content.record_id')
        ->where('assay_records.record_id', $structuredRecordId)
        ->value('content'), false, flags: JSON_THROW_ON_ERROR);
    $admin = User::query()->create(['name' => 'Identity Admin', 'email' => 'identity-admin@example.test']);
    $admin->forceFill(['role' => UserRole::Admin->value])->save();
    $runId = (string) DB::table('assay_runs')->where('invocation_id', 'identity-route-run')->value('id');
    $tree = json_decode((string) $this->actingAs($admin)
        ->getJson(route('assay.runs.tree', $runId))
        ->assertOk()
        ->getContent(), false, flags: JSON_THROW_ON_ERROR);
    $runStart = collect($tree->rows[0]->content_records)->firstWhere('type', 'run.start');
    $stepStart = collect($tree->rows[0]->content_records)->firstWhere('type', 'step.start');
    $stepEnd = collect($tree->rows[0]->content_records)->firstWhere('type', 'step.end');

    expect($stored->structured_output->empty_object)->toBeInstanceOf(stdClass::class)
        ->and($stored->structured_output->empty_list)->toBe([])
        ->and($stored->structured_output->populated_list)->toHaveCount(1)
        ->and($runStart->content->tools[0]->parameters)->toBeInstanceOf(stdClass::class)
        ->and($stepStart->content->new_messages)->toBeInstanceOf(stdClass::class)
        ->and($stepEnd->content->structured_output->empty_object)->toBeInstanceOf(stdClass::class)
        ->and($stepEnd->content->structured_output->empty_list)->toBe([]);
});

it('accepts a mixed envelope when one content record contains an empty object', function (): void {
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'mixed-route-app',
    ]);
    $envelope = EnvelopeFactory::envelope([
        EnvelopeFactory::record([
            'type' => 'step.end',
            'invocation_id' => 'unrelated-usage-run',
            'step' => 0,
            'usage' => ['input_tokens' => 13],
        ]),
        EnvelopeFactory::record([
            'type' => 'step.start',
            'capture' => 'full',
            'invocation_id' => 'mixed-content-run',
            'step' => 0,
            'content' => ['message_hashes' => [], 'new_messages' => (object) []],
        ]),
    ]);

    $this->call('POST', '/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $credential->bearerHeader(),
    ], EnvelopeCodec::encode($envelope))->assertAccepted();

    expect(DB::table('assay_records')->count())->toBe(2)
        ->and(DB::table('assay_usage_metrics')
            ->join('assay_runs', 'assay_runs.id', '=', 'assay_usage_metrics.run_id')
            ->where('assay_runs.invocation_id', 'unrelated-usage-run')
            ->where('metric', 'input_tokens')
            ->value('value'))->toBe('13');
});

it('normalizes null characters in content strings before PostgreSQL persistence', function (): void {
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'null-route-app',
    ]);
    $recordId = (string) UuidV7::generate();
    $message = ['role' => 'user', 'text' => "server message\0value"];
    $hash = hash('sha256', json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $normalizedMessage = ['role' => 'user', 'text' => "server message\u{FFFD}value"];
    $normalizedHash = hash('sha256', json_encode($normalizedMessage, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $envelope = EnvelopeFactory::envelope([
        EnvelopeFactory::record([
            'type' => 'step.start',
            'capture' => 'full',
            'invocation_id' => 'null-route-run',
            'step' => 0,
            'content' => [
                'message_hashes' => [$hash],
                'new_messages' => [$hash => $message],
            ],
        ]),
        EnvelopeFactory::record([
            'record_id' => $recordId,
            'type' => 'step.end',
            'capture' => 'full',
            'invocation_id' => 'null-route-run',
            'step' => 0,
            'content' => [
                'output_text' => "server\0value",
                'structured_output' => (object) [
                    'nested' => ["list\0value", (object) ['text' => "object\0value"]],
                    'empty_object' => (object) [],
                ],
            ],
        ]),
    ]);

    $this->call('POST', '/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $credential->bearerHeader(),
    ], EnvelopeCodec::encode($envelope))->assertAccepted();

    $storedJson = (string) DB::table('assay_record_content')
        ->join('assay_records', 'assay_records.id', '=', 'assay_record_content.record_id')
        ->where('assay_records.record_id', $recordId)
        ->value('content');
    $stored = json_decode($storedJson, false, flags: JSON_THROW_ON_ERROR);
    $storedMessage = json_decode((string) DB::table('assay_messages')->sole()->body, false, flags: JSON_THROW_ON_ERROR);

    expect($storedJson)->not->toContain('\\u0000')
        ->and($stored->output_text)->toBe("server\u{FFFD}value")
        ->and($stored->structured_output->nested[0])->toBe("list\u{FFFD}value")
        ->and($stored->structured_output->nested[1]->text)->toBe("object\u{FFFD}value")
        ->and($stored->structured_output->empty_object)->toBeInstanceOf(stdClass::class)
        ->and($storedMessage->text)->toBe("server message\u{FFFD}value")
        ->and(DB::table('assay_messages')->sole()->hash)->toBe($normalizedHash)
        ->and(DB::table('assay_message_references')->sole()->hash)->toBe($normalizedHash);
});

it('publishes envelope capabilities without authentication', function (): void {
    $this->getJson('/capabilities')
        ->assertOk()
        ->assertJsonPath('envelope.min_major', 1)
        ->assertJsonPath('envelope.max_major', 1)
        ->assertJsonPath('envelope.supported_majors', [1]);
});
