<?php

use App\Console\Commands\ConfigureBuiltForCloud;
use App\Console\Commands\InstallFluxPro;
use App\Console\Commands\OptimizeTailwind;
use App\Console\Commands\ReconcileStaleRuns;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ConfigureBuiltForCloud::class,
        InstallFluxPro::class,
        OptimizeTailwind::class,
        ReconcileStaleRuns::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
