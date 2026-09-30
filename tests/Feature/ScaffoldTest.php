<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\AssayClientServiceProvider;
use ArtisanBuild\AssayContracts\Package;
use Composer\InstalledVersions;

it('loads the Assay path packages and application identity', function (): void {
    $contractsPath = InstalledVersions::getInstallPath('artisan-build/assay-contracts');
    $clientPath = InstalledVersions::getInstallPath('artisan-build/assay-client');

    expect(config('app.name'))->toBe('Assay')
        ->and(config('built-for-cloud.manifest.slug'))->toBe('assay')
        ->and($contractsPath)->toBeString()
        ->and($clientPath)->toBeString()
        ->and(realpath($contractsPath))->toBe(realpath(base_path('packages/assay-contracts')))
        ->and(realpath($clientPath))->toBe(realpath(base_path('packages/assay-client')))
        ->and(class_exists(Package::class))->toBeTrue()
        ->and(app()->getLoadedProviders())->toHaveKey(AssayClientServiceProvider::class, true);
});
