<?php

declare(strict_types=1);

use App\Authorization\AssayCredentialAbility;
use App\Services\UsageIngestProcessor;
use ArtisanBuild\AssayContracts\UuidV7;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

uses(WithCredentials::class);

beforeEach(function (): void {
    expect(Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]))->toBe(0);
    config()->set('built-for-cloud.console.audience', 'https://assay.test');
    config()->set('built-for-cloud.mcp.two_phase.cache_store', 'database');
    config()->set('assay.erasure.journal_disk', 'local');
    config()->set('assay.erasure.journal_prefix', 'assay/erasures');
    config()->set('assay.erasure.active_key_version', 'v1');
    config()->set('assay.erasure.keys', ['v1' => 'MCP-ERASURE-KEY-CANARY-AT-LEAST-32-BYTES']);
    Storage::disk('local')->deleteDirectory('assay/erasures');
});

afterEach(function (): void {
    Storage::disk('local')->deleteDirectory('assay/erasures');
});

it('gives one PostgreSQL-backed winner when duplicate confirmations race', function (): void {
    $subject = 'mcp-confirmation-race-subject';
    $recordId = (string) UuidV7::generate();
    resolve(UsageIngestProcessor::class)->process('mcp-confirmation-race-app', '2026-10-01T12:01:00.000000+00:00', [
        'envelope_id' => (string) UuidV7::generate(),
        'sent_at' => '2026-10-01T12:00:00.000000+00:00',
        'client' => ['package' => 'artisan-build/assay-client', 'version' => '1.0.0'],
        'sources' => [['driver' => 'laravel-ai', 'package' => 'laravel/ai', 'version' => '1.0.1']],
        'environment' => 'testing',
        'deploy' => 'race-deploy',
        'dropped_transport_total' => 0,
        'dropped_hook_total' => 0,
        'records' => [[
            'record_id' => $recordId,
            'source' => 'laravel-ai',
            'type' => 'run.start',
            'operation' => 'agent',
            'invocation_id' => 'mcp-confirmation-race-run',
            'attempt' => 1,
            'at' => '2026-10-01T12:00:00.000000+00:00',
            'capture' => 'full',
            'sampled' => true,
            'subject' => $subject,
            'agent' => 'App\\Ai\\RaceAgent',
            'content' => json_encode(['instructions' => 'MCP-RACE-RAW-CANARY'], JSON_THROW_ON_ERROR),
        ]],
    ]);
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'mcp-confirmation-race',
        'abilities' => [AssayCredentialAbility::Content->value],
    ]);
    $arguments = ['app' => 'mcp-confirmation-race-app', 'subject' => $subject];
    $preview = $this->postJson('/mcp/destructive', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'delete_subject', 'arguments' => $arguments],
    ], ['Authorization' => $credential->bearerHeader()])->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    expect($confirmation)->toBeString();

    $input = json_encode([
        'confirmation' => $confirmation,
        'credential_id' => (string) $credential->credential->getKey(),
        'app_id' => (string) DB::table('assay_apps')->where('app_ref', 'mcp-confirmation-race-app')->value('id'),
        ...$arguments,
    ], JSON_THROW_ON_ERROR);
    $first = new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-mcp-confirmation.php')]);
    $second = new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-mcp-confirmation.php')]);
    $first->setInput($input);
    $second->setInput($input);
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();
    $results = [$first->getOutput(), $second->getOutput()];
    sort($results);

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and($results)->toBe(['executed', 'refused:confirmation_spent'])
        ->and(DB::table('assay_erasure_records')->count())->toBe(1)
        ->and(DB::table('assay_record_content')->where('record_id', $recordId)->count())->toBe(0)
        ->and(json_encode(DB::table('cache')->get(), JSON_THROW_ON_ERROR))->not->toContain($confirmation, $subject, 'MCP-RACE-RAW-CANARY');
});
