<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ErasureJournal;
use App\Services\SubjectErasure;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class ReapplyErasures extends Command
{
    protected $signature = 'assay:erasures:reapply
                            {--since= : Restored snapshot time; only newer journal entries are replayed}
                            {--limit=1000 : Maximum journal entries to replay in this invocation}
                            {--offset=0 : Number of ordered matching entries already processed}';

    protected $description = 'Reapply erasure journal entries newer than a restored database snapshot';

    public function handle(ErasureJournal $journal, SubjectErasure $erasures): int
    {
        $since = $this->option('since');
        $limit = $this->option('limit');
        $offset = $this->option('offset');

        if (! is_string($since) || $since === ''
            || ! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1
            || ! is_string($offset) || ! ctype_digit($offset)) {
            $this->components->error('A valid --since timestamp, positive --limit, and non-negative --offset are required.');

            return self::INVALID;
        }

        try {
            $entries = $journal->newerThan(CarbonImmutable::parse($since), (int) $limit, (int) $offset);
        } catch (Throwable) {
            $this->components->error('The erasure journal could not be read.');

            return self::FAILURE;
        }

        $reapplied = 0;
        $failed = $entries['invalid'];
        $runs = 0;
        $content = 0;
        $datasets = 0;

        foreach ($entries['entries'] as $entry) {
            try {
                $result = $erasures->reapply($entry);
                $reapplied++;
                $runs += $result['runs_affected'];
                $content += $result['content_rows_deleted'];
                $datasets += $result['dataset_items_deleted'];
            } catch (Throwable) {
                $failed++;
            }
        }

        $this->components->info(
            "{$reapplied} journal entries reapplied; {$runs} runs affected; {$content} content rows and "
            ."{$datasets} dataset items deleted; {$failed} entries contained as invalid or failed; "
            ."{$entries['remaining']} ordered entries remain after this page.",
        );

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
