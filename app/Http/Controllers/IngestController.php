<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessUsageEnvelope;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\EnvelopeV1;
use ArtisanBuild\AssayContracts\InvalidEnvelope;
use ArtisanBuild\BuiltForCloud\AppPurposeRegistry;
use ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator;
use ArtisanBuild\BuiltForCloud\CredentialOwnership;
use ArtisanBuild\BuiltForCloud\CredentialUsageRecorder;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use LengthException;

final class IngestController extends Controller
{
    public function store(
        Request $request,
        BearerAuthenticator $authenticator,
        AppPurposeRegistry $purposes,
        CredentialUsageRecorder $usage,
    ): JsonResponse {
        $credential = $authenticator->credential($request);

        if ($credential === null
            || $credential->purpose !== $purposes->purpose('assay.ingest')
            || $credential->subject_type !== SubjectType::Installation
            || $credential->ownership() !== CredentialOwnership::Installation) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $maxBodyBytes = max(1, (int) config('assay.ingest.max_body_bytes', 8_388_608));
        $contentLength = $request->headers->get('Content-Length');

        if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > $maxBodyBytes) {
            return response()->json(['error' => 'payload_too_large', 'limit_bytes' => $maxBodyBytes], 413);
        }

        $stream = $request->getContent(true);
        $body = stream_get_contents($stream, $maxBodyBytes + 1);

        if ($body === false || strlen($body) > $maxBodyBytes) {
            return response()->json(['error' => 'payload_too_large', 'limit_bytes' => $maxBodyBytes], 413);
        }

        $maxRecords = max(1, (int) config('assay.ingest.max_records', 500));
        $maxSources = max(1, (int) config('assay.ingest.max_sources', 16));
        $newerVersion = null;
        $overLimit = null;
        $rejectedContentAttaches = [];

        try {
            $envelope = EnvelopeCodec::decodeForIngest(
                $body,
                static function (int $index, string $recordId) use (&$rejectedContentAttaches): void {
                    $rejectedContentAttaches[] = [
                        'index' => $index,
                        'record_id' => $recordId,
                    ];
                },
                static function (array $decoded) use (
                    $maxRecords,
                    $maxSources,
                    &$newerVersion,
                    &$overLimit,
                ): void {
                    $version = $decoded['envelope_version'] ?? null;

                    if (is_int($version) && $version > EnvelopeV1::VERSION) {
                        $newerVersion = $version;

                        throw new InvalidEnvelope('Envelope version is newer than the server.');
                    }

                    if (is_array($decoded['records'] ?? null) && count($decoded['records']) > $maxRecords) {
                        $overLimit = 'records';

                        throw new LengthException('Envelope has too many records.');
                    }

                    if (is_array($decoded['sources'] ?? null) && count($decoded['sources']) > $maxSources) {
                        $overLimit = 'sources';

                        throw new LengthException('Envelope has too many sources.');
                    }
                },
            );
        } catch (LengthException) {
            return $overLimit === 'records'
                ? response()->json(['error' => 'too_many_records', 'limit' => $maxRecords], 413)
                : response()->json(['error' => 'too_many_sources', 'limit' => $maxSources], 413);
        } catch (InvalidEnvelope $exception) {
            if ($exception->getMessage() === 'Envelope JSON is malformed.') {
                return response()->json(['message' => 'Envelope JSON is malformed.'], 422);
            }

            if ($newerVersion !== null) {
                return response()->json([
                    'message' => 'Envelope v'.$newerVersion.' is newer than this Assay server (max v'.EnvelopeV1::VERSION.'). Upgrade your Assay server.',
                ], 422);
            }

            return response()->json(['message' => 'Envelope is invalid.'], 422);
        }

        if (count($envelope->records) > $maxRecords) {
            return response()->json(['error' => 'too_many_records', 'limit' => $maxRecords], 413);
        }

        if (count($envelope->sources) > $maxSources) {
            return response()->json(['error' => 'too_many_sources', 'limit' => $maxSources], 413);
        }

        if (! $usage->recordUsage($credential)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $job = ProcessUsageEnvelope::fromContract(
            appRef: $credential->subject_ref,
            receivedAt: now()->format('Y-m-d\TH:i:s.uP'),
            envelope: $envelope,
            rejectedContentAttaches: $rejectedContentAttaches,
        );
        $queue = config('assay.queue');

        if (is_string($queue) && $queue !== '') {
            $job->onConnection($queue);
        }

        Bus::dispatch($job);

        return response()->json(['message' => 'Accepted.'], 202);
    }

    public function capabilities(): JsonResponse
    {
        return response()->json([
            'envelope' => [
                'min_major' => 1,
                'max_major' => EnvelopeV1::VERSION,
                'supported_majors' => range(1, EnvelopeV1::VERSION),
            ],
        ]);
    }
}
