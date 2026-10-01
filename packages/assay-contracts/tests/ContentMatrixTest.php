<?php

declare(strict_types=1);

use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\InvalidEnvelope;
use ArtisanBuild\AssayContracts\RecordV1;

function contentPayload(array $overrides): array
{
    return matrixPayload(array_replace([
        'capture' => 'full',
        'sampled' => true,
    ], $overrides));
}

function contentMessageHash(array $message): string
{
    $canonicalize = function (mixed $value) use (&$canonicalize): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($canonicalize, $value);
        }

        ksort($value, SORT_STRING);

        return array_map($canonicalize, $value);
    };

    return hash('sha256', json_encode($canonicalize($message), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

it('round-trips every frozen content shape', function (array $payload): void {
    $record = matrixRoundTrip($payload);
    $actual = $record->toArray();
    $expectedContent = $payload['content'];
    unset($actual['content'], $payload['content']);

    expect($actual)->toMatchArray($payload)
        ->and($record->content?->toArray())->toBe($expectedContent);
})->with([
    'agent start' => fn () => contentPayload(['content' => ['instructions' => 'Help', 'tools' => [['name' => 'lookup', 'description' => 'Lookup', 'parameters' => ['type' => 'object']]]]]),
    'step start' => function (): array {
        $message = ['role' => 'assistant', 'text' => 'Calling', 'tool_calls' => [['id' => 'call-1', 'name' => 'lookup', 'arguments' => ['id' => 1]]]];
        $hash = contentMessageHash($message);

        return contentPayload(['type' => 'step.start', 'step' => 0, 'content' => ['message_hashes' => [$hash], 'new_messages' => [$hash => $message]]]);
    },
    'step end' => fn () => contentPayload(['type' => 'step.end', 'step' => 0, 'content' => ['output_text' => 'Done', 'structured_output' => ['ok' => true], 'tool_calls' => [['id' => 'call-1', 'name' => 'lookup', 'arguments' => []]]]]),
    'tool start' => fn () => contentPayload(['type' => 'tool.start', 'tool_invocation_id' => 'tool-1', 'content' => ['arguments' => ['id' => 1]]]),
    'failed tool end' => fn () => contentPayload(['type' => 'tool.end', 'tool_invocation_id' => 'tool-1', 'outcome' => 'failed', 'content' => ['result' => 'failed', 'exception_message' => 'Nope']]),
    'failed run end' => fn () => contentPayload(['type' => 'run.end', 'outcome' => 'failed', 'content' => ['exception_message' => 'Nope']]),
    'step fail' => fn () => contentPayload(['type' => 'step.fail', 'step' => 0, 'content' => ['exception_message' => 'Nope']]),
    'embeddings start' => fn () => array_diff_key(contentPayload(['operation' => 'embeddings', 'content' => ['inputs' => ['a', 'b']]]), ['attempt' => true]),
    'image start' => fn () => array_diff_key(contentPayload(['operation' => 'image', 'content' => ['prompt' => 'Draw']]), ['attempt' => true]),
    'audio start' => fn () => array_diff_key(contentPayload(['operation' => 'audio', 'content' => ['text' => 'Speak']]), ['attempt' => true]),
    'reranking start' => fn () => array_diff_key(contentPayload(['operation' => 'reranking', 'content' => ['query' => 'q', 'documents' => ['a']]]), ['attempt' => true]),
    'classification start' => fn () => array_diff_key(contentPayload(['operation' => 'classification', 'content' => ['prompt' => 'p', 'labels' => ['yes', 'no']]]), ['attempt' => true]),
    'image end' => fn () => array_diff_key(contentPayload(['type' => 'run.end', 'operation' => 'image', 'outcome' => 'completed', 'content' => ['count' => 1, 'dimensions' => [['width' => 1024, 'height' => 1024]]]]), ['attempt' => true]),
    'transcription end' => fn () => array_diff_key(contentPayload(['type' => 'run.end', 'operation' => 'transcription', 'outcome' => 'completed', 'content' => ['text' => 'Transcript']]), ['attempt' => true]),
    'reranking end' => fn () => array_diff_key(contentPayload(['type' => 'run.end', 'operation' => 'reranking', 'outcome' => 'completed', 'content' => ['results' => [['index' => 0, 'score' => 0.75]]]]), ['attempt' => true]),
    'classification end' => fn () => array_diff_key(contentPayload(['type' => 'run.end', 'operation' => 'classification', 'outcome' => 'completed', 'content' => ['answers' => ['sentiment' => 'positive']]]), ['attempt' => true]),
]);

it('rejects invalid content on construction and wire decode', function (array $payload): void {
    expect(fn () => RecordV1::fromArray($payload))->toThrow(InvalidEnvelope::class);

    try {
        $json = json_encode([
            'envelope_version' => 1,
            'envelope_id' => '0199a447-3c74-7000-8000-000000000001',
            'sent_at' => '2026-09-30T12:34:56.123456Z',
            'client' => ['package' => 'artisan-build/assay-client', 'version' => 'test'],
            'sources' => [['driver' => 'fake', 'package' => 'vendor/fake', 'version' => 'test']],
            'environment' => 'testing',
            'dropped_transport_total' => 0,
            'dropped_hook_total' => 0,
            'records' => [$payload],
        ], JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        expect($exception->getMessage())->toContain('Inf and NaN');

        return;
    }

    expect(fn () => EnvelopeCodec::decode($json))->toThrow(InvalidEnvelope::class);
})->with([
    'usage capture' => fn () => matrixPayload(['content' => ['instructions' => 'x']]),
    'unknown key' => fn () => contentPayload(['content' => ['secret' => 'x']]),
    'forbidden successful run end' => fn () => contentPayload(['type' => 'run.end', 'outcome' => 'completed', 'content' => ['exception_message' => 'x']]),
    'invalid role' => fn () => contentPayload(['type' => 'step.start', 'step' => 0, 'content' => ['message_hashes' => [str_repeat('a', 64)], 'new_messages' => [str_repeat('a', 64) => ['role' => 'developer']]]]),
    'uppercase hash' => fn () => contentPayload(['type' => 'step.start', 'step' => 0, 'content' => ['message_hashes' => [str_repeat('A', 64)]]]),
    'wrong nested type' => fn () => contentPayload(['content' => ['tools' => [['name' => 'x', 'description' => 'x', 'parameters' => []], 'bad']]]),
    'empty content' => fn () => contentPayload(['content' => []]),
    'non finite' => fn () => contentPayload(['type' => 'tool.start', 'tool_invocation_id' => 'tool-1', 'content' => ['arguments' => INF]]),
]);
