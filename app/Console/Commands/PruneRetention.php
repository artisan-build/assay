<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RetentionPruner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class PruneRetention extends Command
{
    protected $signature = 'assay:retention:prune
                            {--limit= : Maximum rows to delete from each bounded phase}
                            {--as-of= : Deterministic cutoff clock; defaults to database time}';

    protected $description = 'Prune content and usage metadata according to the configured retention periods';

    public function handle(RetentionPruner $pruner): int
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
            $this->components->error('Retention configuration or command options are invalid.');

            return self::INVALID;
        }

        $this->components->info(
            "{$result['content_rows_deleted']} content rows, {$result['usage_runs_deleted']} runs, "
            ."{$result['usage_records_deleted']} standalone records, and {$result['envelopes_deleted']} envelopes pruned.",
        );

        return self::SUCCESS;
    }
}
