<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\Internal\CacheDropCounter;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;

it('persists monotonic application and environment scoped drop totals', function (): void {
    $path = sys_get_temp_dir().'/assay-drop-counter-'.bin2hex(random_bytes(8));
    $repository = new Repository(new FileStore(new Filesystem, $path));

    $first = new CacheDropCounter($repository, 'application-a', 'production');

    expect($first->transportTotal())->toBe(0)
        ->and($first->incrementTransport())->toBe(1)
        ->and($first->incrementTransport())->toBe(2)
        ->and($first->incrementHook())->toBe(1);

    $fresh = new CacheDropCounter(new Repository(new FileStore(new Filesystem, $path)), 'application-a', 'production');

    expect($fresh->transportTotal())->toBe(2)
        ->and($fresh->hookTotal())->toBe(1)
        ->and($fresh->transportTotal())->toBe(2)
        ->and((new CacheDropCounter($repository, 'application-b', 'production'))->transportTotal())->toBe(0)
        ->and((new CacheDropCounter($repository, 'application-a', 'staging'))->transportTotal())->toBe(0);

    (new Filesystem)->deleteDirectory($path);
});
