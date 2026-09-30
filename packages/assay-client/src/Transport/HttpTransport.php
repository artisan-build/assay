<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Transport;

use ArtisanBuild\AssayClient\Contracts\Transport;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

final readonly class HttpTransport implements Transport
{
    public function __construct(private Factory $http) {}

    public function send(string $envelopeJson): void
    {
        $url = config('assay.url');
        $token = config('assay.token');

        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            return;
        }

        $this->pendingRequest()
            ->withToken($token)
            ->connectTimeout(max(0.05, (float) config('assay.connect_timeout', 0.5)))
            ->timeout(max(0.05, (float) config('assay.timeout', 5)))
            ->withBody($envelopeJson, 'application/json')
            ->post($url)
            ->throw();
    }

    private function pendingRequest(): PendingRequest
    {
        try {
            return $this->http->withClientIdentity();
        } catch (Throwable) {
            return $this->http->createPendingRequest();
        }
    }
}
