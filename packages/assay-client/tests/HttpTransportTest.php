<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\Transport\HttpTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('is inert when endpoint configuration is incomplete', function (): void {
    config()->set('assay.url', 'https://assay.test/ingest');
    config()->set('assay.token', null);
    Http::preventStrayRequests();

    app(HttpTransport::class)->send('{"safe":true}');

    Http::assertNothingSent();
});

it('sends canonical json with a bearer token when fully configured', function (): void {
    config()->set('assay.url', 'https://assay.test/ingest');
    config()->set('assay.token', 'test-token');
    Http::fake(['https://assay.test/ingest' => Http::response(status: 202)]);

    app(HttpTransport::class)->send('{"safe":true}');

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://assay.test/ingest'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->body() === '{"safe":true}');
});
