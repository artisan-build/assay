<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\BuiltForCloudServiceProvider;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

uses(ContractAssertions::class);

it('loads the Assay manifest and released provider defaults', function (): void {
    $catalogEntry = [
        'name' => 'Assay',
        'slug' => 'assay',
        'description' => 'Self-hosted AI telemetry and dataset curation for Laravel applications.',
        'icon' => 'https://scalpels.app/img/products/transparent/assay.png',
        'product_url' => 'https://scalpels.app/products/assay',
    ];

    $this->assertBuiltForCloudManifestMatches($catalogEntry);

    expect(config('built-for-cloud.manifest'))->toBe($catalogEntry)
        ->and(config('built-for-cloud.ui'))->toBe([
        'landing_page' => true,
        'member_management' => true,
        'personal_credentials' => false,
        'installation_credentials' => true,
        'session_management' => true,
        'managed_transitions' => true,
        'credential_purposes' => ['assay.ingest'],
    ])->and(config('built-for-cloud.product'))->toBe(config('app.name'))
        ->and(config('built-for-cloud.credentials.guard'))->toBe('bfc')
        ->and(config('auth.defaults.guard'))->toBe('web')
        ->and(config('auth.providers.users.model'))->toBe(User::class)
        ->and(app()->getLoadedProviders())->toHaveKey(BuiltForCloudServiceProvider::class, true);
});

it('runs fresh package-owned migrations on PostgreSQL', function (): void {
    expect(Artisan::call('migrate:fresh', [
        '--database' => 'pgsql',
        '--force' => true,
    ]))->toBe(0)
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('bfc_authority'))->toBeTrue()
        ->and(Schema::hasTable('credentials'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'normalized_email'))->toBeTrue()
        ->and(glob(database_path('migrations/*users*')) ?: [])->toBe([])
        ->and(class_exists('App\\Models\\User'))->toBeFalse();
});
