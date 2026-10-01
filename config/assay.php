<?php

declare(strict_types=1);

return [
    'queue' => env('ASSAY_INGEST_QUEUE'),
    'stale_after_minutes' => (int) env('ASSAY_STALE_AFTER_MINUTES', 30),
    'ingest' => [
        'max_body_bytes' => (int) env('ASSAY_INGEST_MAX_BODY_BYTES', 8_388_608),
        'max_records' => (int) env('ASSAY_INGEST_MAX_RECORDS', 500),
        'max_sources' => (int) env('ASSAY_INGEST_MAX_SOURCES', 16),
    ],
    'stale' => [
        'batch_size' => (int) env('ASSAY_STALE_BATCH_SIZE', 1_000),
    ],
    'content_attach' => [
        'batch_size' => (int) env('ASSAY_CONTENT_ATTACH_BATCH_SIZE', 1_000),
    ],
];
