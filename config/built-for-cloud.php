<?php

declare(strict_types=1);

return [
    'manifest' => [
        'name' => 'Assay',
        'slug' => 'assay',
        'description' => 'Self-hosted AI telemetry and dataset curation for Laravel applications.',
        'icon' => 'https://scalpels.app/img/products/transparent/assay.png',
        'product_url' => 'https://scalpels.app/products/assay',
    ],

    'credentials' => [
        'guard' => env('BUILT_FOR_CLOUD_CREDENTIAL_GUARD', 'bfc'),
        'declaration' => null,
        'session_guard' => null,
        'app_purposes' => [
            'assay.ingest' => 'consumption',
        ],
    ],

    'ui' => [
        'landing_page' => true,
        'member_management' => true,
        'personal_credentials' => false,
        'installation_credentials' => true,
        'session_management' => true,
        'managed_transitions' => true,
        'credential_purposes' => ['assay.ingest'],
    ],
];
