<?php

declare(strict_types=1);

use App\Http\Controllers\IngestController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::post('/ingest', [IngestController::class, 'store'])
    ->withoutMiddleware(PreventRequestForgery::class);
Route::get('/capabilities', [IngestController::class, 'capabilities']);
