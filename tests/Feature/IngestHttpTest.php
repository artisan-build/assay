<?php

declare(strict_types=1);

use App\Jobs\ProcessUsageEnvelope;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Tests\Support\EnvelopeFactory;

uses(WithCredentials::class);

it('excludes request forgery protection from only the ingest route', function (): void {
    $ingest = Route::getRoutes()->match(Request::create('/ingest', 'POST'));
    $capabilities = Route::getRoutes()->match(Request::create('/capabilities', 'GET'));

    expect($ingest->excludedMiddleware())->toContain(PreventRequestForgery::class)
        ->and($capabilities->excludedMiddleware())->not->toContain(PreventRequestForgery::class);
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
            'content' => ['secret' => 'CONTENT-CANARY'],
            'forged_app' => 'forged-app',
        ]),
    ]);

    $this->call('POST', '/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $credential->bearerHeader(),
    ], EnvelopeCodec::encode($envelope))->assertAccepted();

    Bus::assertDispatched(ProcessUsageEnvelope::class, function (ProcessUsageEnvelope $job): bool {
        $serialized = serialize($job);

        return $job->appRef === 'credential-app'
            && ! str_contains($serialized, 'CONTENT-CANARY')
            && ! str_contains($serialized, 'forged-app')
            && ! str_contains($serialized, Request::class)
            && ! str_contains($serialized, Credential::class)
            && ! array_key_exists('content', $job->envelope['records'][0]);
    });
    expect($credential->credential->refresh()->last_used_at)->not->toBeNull();
});

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

it('publishes envelope capabilities without authentication', function (): void {
    $this->getJson('/capabilities')
        ->assertOk()
        ->assertJsonPath('envelope.min_major', 1)
        ->assertJsonPath('envelope.max_major', 1)
        ->assertJsonPath('envelope.supported_majors', [1]);
});
