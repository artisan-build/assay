<?php

declare(strict_types=1);

use App\Services\UsageIngestProcessor;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$envelopeId = $argv[1] ?? throw new RuntimeException('Envelope id is required.');
$attachId = $argv[2] ?? throw new RuntimeException('Attach id is required.');
$targetId = $argv[3] ?? throw new RuntimeException('Target id is required.');
$content = $argv[4] ?? throw new RuntimeException('Content is required.');
$invocationId = $argv[5] ?? 'concurrent-attach-run';

$app->make(UsageIngestProcessor::class)->process('concurrent-attach-app', '2026-10-01T12:01:00.000000+00:00', [
    'envelope_id' => $envelopeId,
    'sent_at' => '2026-10-01T12:01:00.000000+00:00',
    'client' => ['package' => 'artisan-build/assay-client', 'version' => '1.0.0'],
    'sources' => [['driver' => 'laravel-ai', 'package' => 'laravel/ai', 'version' => '1.0.1']],
    'environment' => 'testing',
    'deploy' => null,
    'dropped_transport_total' => 0,
    'dropped_hook_total' => 0,
    'records' => [[
        'record_id' => $attachId,
        'type' => 'content.attach',
        'target_record_id' => $targetId,
        'invocation_id' => $invocationId,
        'at' => '2026-10-01T12:01:00.000000+00:00',
        'capture' => 'full',
        'content' => json_encode(['instructions' => $content], JSON_THROW_ON_ERROR),
    ]],
]);
