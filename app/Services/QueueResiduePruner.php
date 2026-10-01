<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ContentStore;
use App\Jobs\ProcessUsageEnvelope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

final readonly class QueueResiduePruner
{
    public function __construct(private ContentStoreRegistry $stores) {}

    /** @return array{pending_jobs_deleted: int, failed_jobs_deleted: int, maximum_hours: int} */
    public function prune(?CarbonImmutable $asOf = null, ?int $limit = null): array
    {
        $asOf ??= $this->databaseNow();
        $limit ??= $this->batchSize();

        if ($limit < 1) {
            throw new RuntimeException('The queue residue batch size must be positive.');
        }

        $maximumHours = $this->stores->boundedResidueHours();
        $cutoff = $asOf->subHours($maximumHours);
        $deleted = [];

        foreach ($this->stores->stores() as $store) {
            if ($store->erasure === 'bounded_residue') {
                $deleted[$store->table] = $this->pruneStore($store, $cutoff, $limit);
            }
        }

        return [
            'pending_jobs_deleted' => $deleted[$this->stores->queueJobs()] ?? 0,
            'failed_jobs_deleted' => $deleted[$this->stores->failedJobs()] ?? 0,
            'maximum_hours' => $maximumHours,
        ];
    }

    private function pruneStore(ContentStore $store, CarbonImmutable $cutoff, int $limit): int
    {
        $deleted = 0;

        do {
            $query = DB::table($store->table)
                ->whereRaw("payload::jsonb ->> 'displayName' = ?", [ProcessUsageEnvelope::class]);
            $ids = match ($store->retention) {
                'queue_timestamp' => $query->where('created_at', '<=', $cutoff->timestamp)
                    ->oldest('created_at')->orderBy('id')->limit($limit)->pluck('id'),
                'failed_queue_timestamp' => $query->where('failed_at', '<=', $cutoff->format('Y-m-d H:i:s.uP'))
                    ->oldest('failed_at')->orderBy('id')->limit($limit)->pluck('id'),
                default => collect(),
            };

            if ($ids->isNotEmpty()) {
                $deleted += DB::table($store->table)->whereIn('id', $ids)->delete();
            }
        } while ($ids->isNotEmpty());

        return $deleted;
    }

    private function batchSize(): int
    {
        $value = config('assay.retention.batch_size');

        if (! is_int($value) || $value < 1) {
            throw new RuntimeException('The retention batch size must be a positive integer.');
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
