<?php

declare(strict_types=1);

use App\Http\Controllers\ContentAccessController;
use App\Http\Controllers\RiskController;
use App\Http\Controllers\UsageDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('assay.access:usage')->group(function (): void {
    Route::get('/assay/dashboard', [UsageDashboardController::class, 'index'])->name('assay.dashboard');
    Route::get('/assay/risk', RiskController::class)->name('assay.risk');

    foreach ([
        'usage-over-time',
        'usage-by-agent',
        'usage-by-subject',
        'top-runs',
        'reliability',
        'latency',
        'pipeline-health',
    ] as $table) {
        Route::get('/assay/dashboard/'.$table, [UsageDashboardController::class, 'show'])
            ->defaults('table', $table)
            ->name('assay.dashboard.'.$table);
        Route::get('/assay/dashboard/'.$table.'.csv', [UsageDashboardController::class, 'csv'])
            ->defaults('table', $table)
            ->name('assay.dashboard.'.$table.'.csv');
    }
});

Route::middleware('assay.access:content')->group(function (): void {
    Route::get('/assay/runs/{run}/tree', [UsageDashboardController::class, 'runTree'])
        ->whereUuid('run')
        ->name('assay.runs.tree');
    Route::get('/assay/access', [ContentAccessController::class, 'index'])->name('assay.access.index');
    Route::put('/assay/access/{actor}', [ContentAccessController::class, 'set'])->name('assay.access.set');
    Route::delete('/assay/access/{actor}', [ContentAccessController::class, 'reset'])->name('assay.access.reset');
});
