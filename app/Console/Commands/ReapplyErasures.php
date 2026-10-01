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
                            {--cursor= : Opaque resume cursor emitted by the previous invocation}';

    protected $description = 'Reapply erasure journal entries newer than a restored database snapshot';

    public function handle(ErasureJournal $journal, SubjectErasure $erasures): int
    {
        $since = $this->option('since');
        $limit = $this->option('limit');
        $cursor = $this->option('cursor');

        if (! is_string($since) || $since === ''
            || ! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1
            || ($cursor !== null && ! is_string($cursor))) {
            $this->components->error('A valid --since timestamp, positive --limit, and optional --cursor are required.');

            return self::INVALID;
        }

        try {
            $entries = $journal->newerThan(CarbonImmutable::parse($since), (int) $limit, $cursor);
        } catch (Throwable) {
            $this->components->error('The erasure journal could not be read.');

            return self::FAILURE;
        }

        $reapplied = 0;
        $invalid = 0;
        $failed = 0;
        $runs = 0;
        $content = 0;
        $datasets = 0;
        $resumeCursor = $cursor;
        $hasMore = $entries['has_more'];

        foreach ($entries['items'] as $item) {
            if ($item['entry'] === null) {
                $invalid++;
                $resumeCursor = $item['cursor'];

                continue;
            }

            try {
                $result = $erasures->reapply($item['entry']);
                $reapplied++;
                $runs += $result['runs_affected'];
                $content += $result['content_rows_deleted'];
                $datasets += $result['dataset_items_deleted'];
                $resumeCursor = $item['cursor'];
            } catch (Throwable) {
                $failed++;
                $hasMore = true;

                break;
            }
        }

        $resume = ($hasMore || $invalid > 0) && $resumeCursor !== null ? " Resume with --cursor={$resumeCursor}." : '';
        $this->components->info(
            "{$reapplied} journal entries reapplied; {$runs} runs affected; {$content} content rows and "
            ."{$datasets} dataset items deleted; {$invalid} invalid entries contained; {$failed} entries failed."
            .$resume,
        );

        return $invalid === 0 && $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
