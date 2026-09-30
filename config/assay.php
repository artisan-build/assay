<?php

declare(strict_types=1);

return [
    'queue' => env('ASSAY_INGEST_QUEUE'),
    'stale_after_minutes' => (int) env('ASSAY_STALE_AFTER_MINUTES', 30),
];
