<?php

declare(strict_types=1);

use App\Services\ContentStoreRegistry;
use Illuminate\Support\Facades\DB;

it('classifies every text and json column in app-owned content-capable schemas', function (): void {
    $registry = resolve(ContentStoreRegistry::class);
    $classified = $registry->nonContentColumns();

    foreach ($registry->contentColumns() as $table => $columns) {
        foreach ($columns as $column) {
            $classified[] = $table.'.'.$column;
        }
    }

    sort($classified);
    $actual = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->whereIn('data_type', ['text', 'character varying', 'json', 'jsonb'])
        ->where(function ($query): void {
            $query->where('table_name', 'like', 'assay\_%')
                ->orWhereIn('table_name', ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs']);
        })
        ->orderBy('table_name')
        ->orderBy('column_name')
        ->get(['table_name', 'column_name'])
        ->map(static fn (object $column): string => $column->table_name.'.'.$column->column_name)
        ->all();

    expect($classified)->toBe($actual)
        ->and($registry->contentColumns())->toBe([
            'assay_record_content' => ['content'],
            'assay_messages' => ['body'],
            'assay_pending_content_attaches' => ['content'],
            'jobs' => ['payload'],
            'failed_jobs' => ['payload', 'exception'],
        ]);
});

it('assigns executable retention and erasure behavior to every registered store', function (): void {
    foreach (resolve(ContentStoreRegistry::class)->stores() as $store) {
        expect($store->retention)->not->toBe('')
            ->and($store->erasure)->not->toBe('')
            ->and($store->contentColumns)->not->toBeEmpty();
    }
});
