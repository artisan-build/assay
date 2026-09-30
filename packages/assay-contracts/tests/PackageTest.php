<?php

declare(strict_types=1);

use ArtisanBuild\AssayContracts\Package;

it('autoloads the contracts package namespace', function (): void {
    expect(new Package)->toBeInstanceOf(Package::class);
});
