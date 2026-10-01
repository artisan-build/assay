<?php

declare(strict_types=1);

use App\Http\Controllers\IngestController;
use Illuminate\Support\Facades\Route;

Route::post('/ingest', [IngestController::class, 'store'])->name('assay.ingest');
Route::get('/capabilities', [IngestController::class, 'capabilities'])->name('assay.capabilities');
