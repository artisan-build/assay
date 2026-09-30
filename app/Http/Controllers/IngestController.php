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
use JsonException;

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
            || $credential->ownership() !== CredentialOwnership::Installation
            || ! $usage->recordUsage($credential)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $body = $request->getContent();

        try {
            $peek = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'Envelope JSON is malformed.'], 422);
        }

        if (is_array($peek)
            && isset($peek['envelope_version'])
            && is_int($peek['envelope_version'])
            && $peek['envelope_version'] > EnvelopeV1::VERSION) {
            return response()->json([
                'message' => 'Envelope v'.$peek['envelope_version'].' is newer than this Assay server (max v'.EnvelopeV1::VERSION.'). Upgrade your Assay server.',
            ], 422);
        }

        try {
            $envelope = EnvelopeCodec::decode($body);
        } catch (InvalidEnvelope) {
            return response()->json(['message' => 'Envelope is invalid.'], 422);
        }

        $job = ProcessUsageEnvelope::fromContract(
            appRef: $credential->subject_ref,
            receivedAt: now()->format('Y-m-d\TH:i:s.uP'),
            envelope: $envelope,
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
