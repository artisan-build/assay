<?php

use App\Console\Commands\ConfigureBuiltForCloud;
use App\Console\Commands\InstallFluxPro;
use App\Console\Commands\OptimizeTailwind;
use App\Console\Commands\ReconcileStaleRuns;
use App\Http\Middleware\EnsureAssayAccess;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as FrameworkRequestForgery;

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
        $middleware->web(replace: [
            FrameworkRequestForgery::class => PreventRequestForgery::class,
        ]);
        $middleware->alias([
            'assay.access' => EnsureAssayAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
