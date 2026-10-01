<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\UsageIngestProcessor;
use Illuminate\Console\Command;

final class ReconcileStaleRuns extends Command
{
    protected $signature = 'assay:reconcile-stale-runs
                            {--limit= : Maximum number of stale runs to reconcile}';

    protected $description = 'Mark received runs without terminal records after the configured stale window';

    public function handle(UsageIngestProcessor $processor): int
    {
        $option = $this->option('limit');
        $valid = $option === null
            || (is_string($option) && ctype_digit($option) && (int) $option > 0);

        if (! $valid) {
            $this->components->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }

        $limit = $option === null ? null : (int) $option;
        $this->components->info($processor->reconcileStale(limit: $limit).' stale runs reconciled.');

        return self::SUCCESS;
    }
}
