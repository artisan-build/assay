<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\QueueResiduePruner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class PruneQueueResidue extends Command
{
    protected $signature = 'assay:queue:prune-residue
                            {--limit= : Maximum rows to delete in each bounded batch}
                            {--as-of= : Deterministic cutoff clock; defaults to database time}';

    protected $description = 'Prune encrypted Assay ingest queue residue within its configured hard bound';

    public function handle(QueueResiduePruner $pruner): int
    {
        $limit = $this->option('limit');

        if ($limit !== null && (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1)) {
            $this->components->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }

        try {
            $asOf = is_string($this->option('as-of')) && $this->option('as-of') !== ''
                ? CarbonImmutable::parse((string) $this->option('as-of'))
                : null;
            $result = $pruner->prune($asOf, $limit === null ? null : (int) $limit);
        } catch (Throwable) {
            $this->components->error('Queue residue configuration or command options are invalid.');

            return self::INVALID;
        }

        $this->components->info(
            "{$result['pending_jobs_deleted']} pending and {$result['failed_jobs_deleted']} failed encrypted ingest jobs pruned; "
            ."the effective residue bound is {$result['maximum_hours']} hours.",
        );

        return self::SUCCESS;
    }
}
