<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ContentStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

final readonly class RetentionPruner
{
    public function __construct(private ContentStoreRegistry $stores) {}

    /** @return array{content_rows_deleted: int, usage_runs_deleted: int, usage_records_deleted: int, envelopes_deleted: int} */
    public function prune(?CarbonImmutable $asOf = null, ?int $limit = null): array
    {
        $asOf ??= $this->databaseNow();
        $limit ??= $this->positiveConfig('assay.retention.batch_size');

        if ($limit < 1) {
            throw new RuntimeException('The retention batch size must be a positive integer.');
        }

        $contentDays = $this->positiveConfig('assay.retention.run_content_days');
        $usageDays = $this->positiveConfig('assay.retention.usage_days');
        $contentCutoff = $asOf->subDays($contentDays);
        $usageCutoff = $asOf->subDays($usageDays);
        $contentDeleted = 0;

        foreach ($this->stores->stores() as $store) {
            $contentDeleted += $this->pruneStore($store, $contentCutoff, $limit);
        }

        $runsDeleted = 0;

        do {
            $runIds = DB::table('assay_runs')
                ->whereRaw('COALESCE(ended_at, started_at, earliest_received_at) <= ?', [$usageCutoff->format('Y-m-d H:i:s.uP')])
                ->orderByRaw('COALESCE(ended_at, started_at, earliest_received_at)')
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');

            if ($runIds->isNotEmpty()) {
                $runsDeleted += DB::table('assay_runs')->whereIn('id', $runIds)->delete();
            }
        } while ($runIds->isNotEmpty());

        $recordsDeleted = 0;

        do {
            $recordIds = DB::table('assay_records')
                ->whereNull('run_id')
                ->where('occurred_at', '<=', $usageCutoff->format('Y-m-d H:i:s.uP'))
                ->oldest('occurred_at')->orderBy('id')->limit($limit)->pluck('id');

            if ($recordIds->isNotEmpty()) {
                $recordsDeleted += DB::table('assay_records')->whereIn('id', $recordIds)->delete();
            }
        } while ($recordIds->isNotEmpty());

        $envelopesDeleted = 0;

        do {
            $envelopeIds = DB::table('assay_envelopes as envelope')
                ->where('envelope.received_at', '<=', $usageCutoff->format('Y-m-d H:i:s.uP'))
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('assay_records as record')->whereColumn('record.envelope_id', 'envelope.id');
                })
                ->oldest('envelope.received_at')->orderBy('envelope.id')->limit($limit)->pluck('envelope.id');

            if ($envelopeIds->isNotEmpty()) {
                $envelopesDeleted += DB::table('assay_envelopes')->whereIn('id', $envelopeIds)->delete();
            }
        } while ($envelopeIds->isNotEmpty());

        return [
            'content_rows_deleted' => $contentDeleted,
            'usage_runs_deleted' => $runsDeleted,
            'usage_records_deleted' => $recordsDeleted,
            'envelopes_deleted' => $envelopesDeleted,
        ];
    }

    private function pruneStore(ContentStore $store, CarbonImmutable $cutoff, int $limit): int
    {
        $deleted = 0;

        do {
            $ids = match ($store->retention) {
                'run_record' => DB::table($store->table.' as content')
                    ->join('assay_runs as run', 'run.id', '=', 'content.run_id')
                    ->whereRaw('COALESCE(run.ended_at, run.started_at, run.earliest_received_at) <= ?', [$cutoff->format('Y-m-d H:i:s.uP')])
                    ->orderBy('content.id')->limit($limit)->pluck('content.id'),
                'run_message' => DB::table($store->table.' as content')
                    ->join('assay_runs as run', 'run.id', '=', 'content.run_id')
                    ->whereRaw('COALESCE(run.ended_at, run.started_at, run.earliest_received_at) <= ?', [$cutoff->format('Y-m-d H:i:s.uP')])
                    ->orderBy('content.id')->limit($limit)->pluck('content.id'),
                'timestamp' => DB::table($store->table)->where('occurred_at', '<=', $cutoff->format('Y-m-d H:i:s.uP'))
                    ->oldest('occurred_at')->orderBy('id')->limit($limit)->pluck('id'),
                'unix_timestamp' => DB::table($store->table)->where('created_at', '<=', $cutoff->timestamp)->oldest()->orderBy('id')->limit($limit)->pluck('id'),
                'failed_timestamp' => DB::table($store->table)->where('failed_at', '<=', $cutoff->format('Y-m-d H:i:s.uP'))
                    ->oldest('failed_at')->orderBy('id')->limit($limit)->pluck('id'),
                default => collect(),
            };

            if ($ids->isNotEmpty()) {
                $deleted += DB::table($store->table)->whereIn('id', $ids)->delete();
            }
        } while ($ids->isNotEmpty());

        return $deleted;
    }

    private function positiveConfig(string $key): int
    {
        $value = config($key);

        if (! is_int($value) || $value < 1) {
            throw new RuntimeException("The {$key} configuration must be a positive integer.");
        }

        return $value;
    }

    private function databaseNow(): CarbonImmutable
    {
        /** @var stdClass $row */
        $row = DB::selectOne('SELECT CURRENT_TIMESTAMP AS current_time');

        return CarbonImmutable::parse((string) $row->current_time);
    }
}
