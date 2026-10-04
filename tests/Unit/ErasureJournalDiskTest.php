<?php

declare(strict_types=1);

use Illuminate\Support\Env;

/**
 * Resolve config/assay.php with the given environment, bypassing the booted
 * application's already-evaluated configuration.
 *
 * @param  array<string, string|null>  $environment
 * @return array<string, mixed>
 */
function assayConfigWithEnvironment(array $environment): array
{
    $repository = Env::getRepository();
    $previous = [];

    foreach ($environment as $key => $value) {
        $previous[$key] = $repository->get($key);

        $value === null ? $repository->clear($key) : $repository->set($key, $value);
    }

    try {
        return require dirname(__DIR__, 2).'/config/assay.php';
    } finally {
        foreach ($previous as $key => $value) {
            $value === null ? $repository->clear($key) : $repository->set($key, $value);
        }
    }
}

test('the erasure journal disk falls back to the default filesystem disk', function (): void {
    $config = assayConfigWithEnvironment([
        'ASSAY_ERASURE_JOURNAL_DISK' => null,
        'FILESYSTEM_DISK' => 'private',
    ]);

    expect($config['erasure']['journal_disk'])->toBe('private');
});

test('the erasure journal disk falls back to local without a default filesystem disk', function (): void {
    $config = assayConfigWithEnvironment([
        'ASSAY_ERASURE_JOURNAL_DISK' => null,
        'FILESYSTEM_DISK' => null,
    ]);

    expect($config['erasure']['journal_disk'])->toBe('local');
});

test('an explicit erasure journal disk wins over the default filesystem disk', function (): void {
    $config = assayConfigWithEnvironment([
        'ASSAY_ERASURE_JOURNAL_DISK' => 'erasure-journal',
        'FILESYSTEM_DISK' => 'private',
    ]);

    expect($config['erasure']['journal_disk'])->toBe('erasure-journal');
});
