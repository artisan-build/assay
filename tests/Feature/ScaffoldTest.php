<?php

declare(strict_types=1);

use ArtisanBuild\AssayContracts\EnvelopeV1;
use Composer\InstalledVersions;

it('loads the Assay contracts path package and application identity', function (): void {
    $contractsPath = InstalledVersions::getInstallPath('artisan-build/assay-contracts');

    expect(config('app.name'))->toBe('Assay')
        ->and(config('built-for-cloud.manifest.slug'))->toBe('assay')
        ->and($contractsPath)->toBeString()
        ->and(realpath($contractsPath))->toBe(realpath(base_path('packages/assay-contracts')))
        ->and(class_exists(EnvelopeV1::class))->toBeTrue();
});
