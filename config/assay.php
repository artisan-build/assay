<?php

declare(strict_types=1);

$historicalErasureKeys = json_decode((string) env('ASSAY_ERASURE_HISTORICAL_KEYS', '{}'), true);
$activeErasureVersion = (string) env('ASSAY_ERASURE_KEY_VERSION', 'v1');
$activeErasureKey = (string) env('ASSAY_ERASURE_KEY', '');
$erasureKeys = is_array($historicalErasureKeys)
    ? [$activeErasureVersion => $activeErasureKey] + $historicalErasureKeys
    : null;

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
    'retention' => [
        'run_content_days' => (int) env('ASSAY_RUN_CONTENT_RETENTION_DAYS', 30),
        'usage_days' => (int) env('ASSAY_USAGE_RETENTION_DAYS', 395),
        'dataset_days' => (int) env('ASSAY_DATASET_RETENTION_DAYS', 365),
        'batch_size' => (int) env('ASSAY_RETENTION_BATCH_SIZE', 1_000),
    ],
    'erasure' => [
        'active_key_version' => $activeErasureVersion,
        'keys' => $erasureKeys,
        'batch_size' => (int) env('ASSAY_ERASURE_BATCH_SIZE', 1_000),
        'journal_disk' => env('ASSAY_ERASURE_JOURNAL_DISK', 'local'),
        'journal_prefix' => env('ASSAY_ERASURE_JOURNAL_PREFIX', 'assay/erasures'),
    ],
];
