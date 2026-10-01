<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\AssayClientServiceProvider;
use ArtisanBuild\AssayClient\CaptureDriver;
use ArtisanBuild\AssayClient\LaravelAiDriver;

it('loads the client service provider', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(AssayClientServiceProvider::class, true)
        ->and(resolve(CaptureDriver::class))->toBeInstanceOf(LaravelAiDriver::class)
        ->and(config('assay.batch_size'))->toBe(100)
        ->and(config('assay.max_batch_bytes'))->toBe(4_194_304)
        ->and(config('assay.sample_rate'))->toBe(1.0)
        ->and(config('assay.always_on_failure'))->toBeTrue()
        ->and(config('assay.failure_buffer_bytes'))->toBe(524_288)
        ->and(config('assay.subject_context_key'))->toBe('assay.subject');
});

it('accepts 500 records per batch during boot', function (): void {
    config()->set('assay.batch_size', 500);

    (new AssayClientServiceProvider(app()))->boot();

    expect(config('assay.batch_size'))->toBe(500);
});

it('refuses more than 500 records per batch during boot', function (): void {
    config()->set('assay.batch_size', 501);

    expect(fn () => (new AssayClientServiceProvider(app()))->boot())
        ->toThrow(InvalidArgumentException::class, 'Assay batch_size must not exceed 500.');
});

it('reads the maximum batch bytes from its environment setting', function (): void {
    putenv('ASSAY_MAX_BATCH_BYTES=123456');

    try {
        $configuration = require __DIR__.'/../config/assay.php';
    } finally {
        putenv('ASSAY_MAX_BATCH_BYTES');
    }

    expect($configuration['max_batch_bytes'])->toBe(123456);
});

it('refuses non-positive client bounds during boot', function (string $key): void {
    config()->set("assay.{$key}", 0);

    expect(fn () => (new AssayClientServiceProvider(app()))->boot())
        ->toThrow(InvalidArgumentException::class, 'must be positive.');
})->with([
    'batch size' => 'batch_size',
    'retry bound' => 'retry_for_seconds',
    'maximum batch bytes' => 'max_batch_bytes',
    'failure buffer bytes' => 'failure_buffer_bytes',
]);

it('rejects invalid global and per-agent sample rates', function (string $key, mixed $value, string $message): void {
    config()->set($key, $value);

    expect(fn () => (new AssayClientServiceProvider(app()))->boot())
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'negative global' => ['assay.sample_rate', -0.1, 'sample_rate'],
    'above-one global' => ['assay.sample_rate', 1.1, 'sample_rate'],
    'non-numeric global' => ['assay.sample_rate', 'invalid', 'sample_rate'],
    'invalid override' => ['assay.agent_sample_rates', ['App\\Ai\\Agent' => 2], 'agent sample rate'],
    'non-array overrides' => ['assay.agent_sample_rates', 'invalid', 'agent_sample_rates'],
]);
