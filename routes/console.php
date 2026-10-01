<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('assay:reconcile-stale-runs')->everyMinute()->withoutOverlapping();
Schedule::command('assay:reconcile-content-attaches')->everyMinute()->withoutOverlapping();

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
