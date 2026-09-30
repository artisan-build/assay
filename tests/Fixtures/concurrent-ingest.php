<?php

declare(strict_types=1);

use App\Services\UsageIngestProcessor;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$envelopeId = $argv[1] ?? throw new RuntimeException('Envelope id is required.');
$recordId = $argv[2] ?? throw new RuntimeException('Record id is required.');

$app->make(UsageIngestProcessor::class)->process('concurrent-app', '2026-10-01T12:00:00.000000+00:00', [
    'envelope_id' => $envelopeId,
    'sent_at' => '2026-10-01T11:59:59.000000+00:00',
    'client' => ['package' => 'artisan-build/assay-client', 'version' => '1.0.0'],
    'sources' => [['driver' => 'laravel-ai', 'package' => 'laravel/ai', 'version' => '1.0.1']],
    'environment' => 'testing',
    'deploy' => null,
    'dropped_transport_total' => 1,
    'dropped_hook_total' => 2,
    'records' => [[
        'record_id' => $recordId,
        'source' => 'laravel-ai',
        'type' => 'run.start',
        'operation' => 'agent',
        'invocation_id' => 'concurrent-run',
        'attempt' => 1,
        'at' => '2026-10-01T11:59:59.000000+00:00',
        'capture' => 'usage',
        'sampled' => true,
    ]],
]);
