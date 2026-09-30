<?php

declare(strict_types=1);

use App\Http\Controllers\IngestController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::post('/ingest', [IngestController::class, 'store'])
    ->withoutMiddleware(ValidateCsrfToken::class);
Route::get('/capabilities', [IngestController::class, 'capabilities']);
