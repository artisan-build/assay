<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\UsageIngestProcessor;
use Illuminate\Console\Command;

final class ReconcileStaleRuns extends Command
{
    protected $signature = 'assay:reconcile-stale-runs';

    protected $description = 'Mark received runs without terminal records after the configured stale window';

    public function handle(UsageIngestProcessor $processor): int
    {
        $this->components->info($processor->reconcileStale().' stale runs reconciled.');

        return self::SUCCESS;
    }
}
