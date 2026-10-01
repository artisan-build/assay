<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Testing\PayloadFilterAssertions;
use ArtisanBuild\BuiltForCloudContracts\OutboundPayload;
use ArtisanBuild\BuiltForCloudContracts\PayloadDisposition;

it('uses the released helper to prove unhandled products pass through', function (): void {
    PayloadFilterAssertions::assertPassesThroughUnhandledProducts(app(), [
        'assay' => new OutboundPayload('assay', 'run.start', 1, PayloadDisposition::Droppable, [], []),
        'mail' => new OutboundPayload('mail', 'message', 1, PayloadDisposition::Deliverable, [], []),
    ], ['assay']);

    expect(true)->toBeTrue();
});
