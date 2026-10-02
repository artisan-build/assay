<?php

declare(strict_types=1);

use App\Services\SubjectErasure;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\Mcp\CanonicalToolArguments;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseConfirmationRefused;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseConfirmationStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Laravel\Mcp\Transport\JsonRpcRequest;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config()->set('built-for-cloud.console.audience', 'https://assay.test');
config()->set('built-for-cloud.mcp.two_phase.cache_store', 'database');
config()->set('assay.erasure.journal_disk', 'local');
config()->set('assay.erasure.journal_prefix', 'assay/erasures');
config()->set('assay.erasure.active_key_version', 'v1');
config()->set('assay.erasure.keys', ['v1' => 'MCP-ERASURE-KEY-CANARY-AT-LEAST-32-BYTES']);

$input = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($input)) {
    throw new RuntimeException('Confirmation input must be an object.');
}

$arguments = ['app' => $input['app'], 'subject' => $input['subject']];
$payload = [
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'tools/call',
    'params' => [
        'name' => 'delete_subject',
        'arguments' => [...$arguments, 'confirm' => $input['confirmation']],
    ],
];
$http = Request::create(
    '/mcp/destructive',
    'POST',
    server: ['CONTENT_TYPE' => 'application/json'],
    content: json_encode($payload, JSON_THROW_ON_ERROR),
);
$rpc = JsonRpcRequest::from($payload);
$canonical = $app->make(CanonicalToolArguments::class)->fromHttpRequest($http, $rpc);

try {
    $app->make(TwoPhaseConfirmationStore::class)->burn(
        (string) $input['confirmation'],
        'delete_subject',
        $canonical,
        Credential::class.'#'.(string) $input['credential_id'],
    );
    $app->make(SubjectErasure::class)->erase((string) $input['app_id'], (string) $input['subject']);
    fwrite(STDOUT, 'executed');
} catch (TwoPhaseConfirmationRefused $refused) {
    fwrite(STDOUT, 'refused:'.$refused->getMessage());
}
