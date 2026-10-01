<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ContentStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final readonly class SubjectErasure
{
    public function __construct(
        private SubjectErasureHasher $hasher,
        private SubjectErasureBarrier $barrier,
        private ContentStoreRegistry $stores,
        private ErasureJournal $journal,
    ) {}

    /** @return array{runs_affected: int, content_rows_deleted: int, dataset_items_deleted: int, bounded_residue: array{stores: list<string>, protection: string, maximum_hours: int}} */
    public function erase(string $appId, string $subject, ?CarbonImmutable $cutoff = null): array
    {
        if ($subject === '') {
            throw new RuntimeException('The erasure subject must not be empty.');
        }

        return DB::transaction(function () use ($appId, $subject, $cutoff): array {
            $this->assertAppExists($appId);
            $this->barrier->lock($appId, $subject);
            $existing = $this->barrier->find($appId, $subject);

            if (str_starts_with($subject, 'deleted:') && $existing === null) {
                throw new RuntimeException('The erasure tombstone is unknown.');
            }

            $identity = $existing === null
                ? $this->hasher->active($subject)
                : [
                    'version' => (string) $existing->key_version,
                    'lookup_key' => (string) $existing->lookup_key,
                    'tombstone' => (string) $existing->tombstone,
                ];
            $now = $this->databaseNow();
            $requestedCutoff = $cutoff ?? $now;
            $effectiveCutoff = $existing !== null && CarbonImmutable::parse((string) $existing->cutoff_at)->greaterThan($requestedCutoff)
                ? CarbonImmutable::parse((string) $existing->cutoff_at)
                : $requestedCutoff;
            $erasureId = $existing === null ? (string) Str::uuid() : (string) $existing->id;

            $this->journal->append([
                'schema' => 1,
                'entry_id' => (string) Str::uuid(),
                'erasure_id' => $erasureId,
                'app_id' => $appId,
                'key_version' => $identity['version'],
                'lookup_key' => $identity['lookup_key'],
                'tombstone' => $identity['tombstone'],
                'cutoff_at' => $effectiveCutoff->format('Y-m-d\TH:i:s.uP'),
                'recorded_at' => $now->format('Y-m-d\TH:i:s.uP'),
            ]);
            $this->persistRecord($erasureId, $appId, $identity, $effectiveCutoff, $now);

            $subjects = [$subject, $identity['tombstone']];

            if (! str_starts_with($subject, 'deleted:')) {
                $subjects = [...$subjects, ...array_column($this->hasher->candidates($subject), 'tombstone')];
            }

            return $this->sweep($appId, array_values(array_unique($subjects)), $identity['tombstone'], $effectiveCutoff);
        }, 3);
    }

    /**
     * @param  array{schema: int, entry_id: string, erasure_id: string, app_id: string, key_version: string, lookup_key: string, tombstone: string, cutoff_at: string, recorded_at: string}  $entry
     * @return array{runs_affected: int, content_rows_deleted: int, dataset_items_deleted: int, bounded_residue: array{stores: list<string>, protection: string, maximum_hours: int}}
     */
    public function reapply(array $entry): array
    {
        return DB::transaction(function () use ($entry): array {
            $this->assertAppExists($entry['app_id']);
            $this->barrier->lock($entry['app_id'], $entry['tombstone']);
            $now = $this->databaseNow();
            $cutoff = CarbonImmutable::parse($entry['cutoff_at']);
            $existing = DB::table('assay_erasure_records')
                ->where('app_id', $entry['app_id'])
                ->where(function (Builder $query) use ($entry): void {
                    $query->where('tombstone', $entry['tombstone'])
                        ->orWhere(function (Builder $query) use ($entry): void {
                            $query->where('key_version', $entry['key_version'])
                                ->where('lookup_key', $entry['lookup_key']);
                        });
                })
                ->first();

            if ($existing !== null && CarbonImmutable::parse((string) $existing->cutoff_at)->greaterThan($cutoff)) {
                $cutoff = CarbonImmutable::parse((string) $existing->cutoff_at);
            }

            $identity = [
                'version' => $entry['key_version'],
                'lookup_key' => $entry['lookup_key'],
                'tombstone' => $entry['tombstone'],
            ];
            $this->persistRecord(
                $existing === null ? $entry['erasure_id'] : (string) $existing->id,
                $entry['app_id'],
                $identity,
                $cutoff,
                $now,
            );

            $subjects = [$entry['tombstone']];
            DB::table('assay_runs')
                ->where('app_id', $entry['app_id'])
                ->whereNotNull('subject')
                ->orderBy('id')
                ->chunkById($this->batchSize(), function (Collection $runs) use (&$subjects, $entry): void {
                    foreach ($runs as $run) {
                        $subject = (string) $run->subject;

                        if (! str_starts_with($subject, 'deleted:')
                            && $this->hasher->matches($entry['key_version'], $subject, $entry['lookup_key'])) {
                            $subjects[] = $subject;
                        }
                    }
                });

            return $this->sweep($entry['app_id'], array_values(array_unique($subjects)), $entry['tombstone'], $cutoff);
        }, 3);
    }

    /**
     * @param  array{version: string, lookup_key: string, tombstone: string}  $identity
     */
    private function persistRecord(
        string $id,
        string $appId,
        array $identity,
        CarbonImmutable $cutoff,
        CarbonImmutable $now,
    ): void {
        DB::table('assay_erasure_records')->upsert([[
            'id' => $id,
            'app_id' => $appId,
            'key_version' => $identity['version'],
            'lookup_key' => $identity['lookup_key'],
            'tombstone' => $identity['tombstone'],
            'cutoff_at' => $cutoff->format('Y-m-d H:i:s.uP'),
            'created_at' => $now->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]], ['app_id', 'key_version', 'lookup_key'], ['cutoff_at', 'updated_at']);
    }

    /**
     * @param  list<string>  $subjects
     * @return array{runs_affected: int, content_rows_deleted: int, dataset_items_deleted: int, bounded_residue: array{stores: list<string>, protection: string, maximum_hours: int}}
     */
    private function sweep(string $appId, array $subjects, string $tombstone, CarbonImmutable $cutoff): array
    {
        $runIds = $this->runIds($appId, $subjects);
        $deleted = 0;
        $datasetItemsDeleted = 0;

        foreach ($this->stores->stores() as $store) {
            if ($store->erasure === 'dataset') {
                $count = $this->eraseStore($store, $appId, [], $cutoff, $subjects);
                $deleted += $count;
                $datasetItemsDeleted += $count;

                continue;
            }

            foreach (array_chunk($runIds, $this->batchSize()) as $runIdBatch) {
                $deleted += $this->eraseStore($store, $appId, $runIdBatch, $cutoff, $subjects);
            }
        }

        $runsAffected = 0;

        foreach (array_chunk($runIds, $this->batchSize()) as $runIdBatch) {
            $runsAffected += DB::table('assay_runs')
                ->whereIn('id', $runIdBatch)
                ->where(function (Builder $query) use ($tombstone): void {
                    $query->whereNull('subject')->orWhere('subject', '!=', $tombstone);
                })
                ->update(['subject' => $tombstone]);
        }

        return [
            'runs_affected' => $runsAffected,
            'content_rows_deleted' => $deleted,
            'dataset_items_deleted' => $datasetItemsDeleted,
            'bounded_residue' => $this->stores->boundedResidueReport(),
        ];
    }

    /**
     * @param  list<string>  $subjects
     * @return list<string>
     */
    private function runIds(string $appId, array $subjects): array
    {
        /** @var list<string> $runIds */
        $runIds = DB::table('assay_runs')
            ->where('app_id', $appId)
            ->whereIn('subject', $subjects)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        $frontier = $runIds;

        while ($frontier !== []) {
            $children = [];

            foreach (array_chunk($frontier, $this->batchSize()) as $parents) {
                foreach (DB::table('assay_runs')->where('app_id', $appId)->whereIn('parent_run_id', $parents)->pluck('id') as $id) {
                    $value = (string) $id;

                    if (! in_array($value, $runIds, true)) {
                        $runIds[] = $value;
                        $children[] = $value;
                    }
                }
            }

            $frontier = $children;
        }

        return $runIds;
    }

    /**
     * @param  list<string>  $runIds
     * @param  list<string>  $subjects
     */
    private function eraseStore(ContentStore $store, string $appId, array $runIds, CarbonImmutable $cutoff, array $subjects): int
    {
        if (($runIds === [] && $store->erasure !== 'dataset') || $store->erasure === 'bounded_residue') {
            return 0;
        }

        $deleted = 0;

        do {
            $ids = match ($store->erasure) {
                'run_record' => DB::table($store->table.' as content')
                    ->join('assay_records as record', 'record.id', '=', 'content.record_id')
                    ->whereIn('content.run_id', $runIds)
                    ->where('record.occurred_at', '<=', $cutoff->format('Y-m-d H:i:s.uP'))
                    ->orderBy('content.id')->limit($this->batchSize())->pluck('content.id'),
                'run_message' => DB::table($store->table.' as message')
                    ->whereIn('message.run_id', $runIds)
                    ->whereExists(function (Builder $query) use ($cutoff): void {
                        $query->selectRaw('1')
                            ->from('assay_message_references as reference')
                            ->join('assay_records as record', 'record.id', '=', 'reference.record_id')
                            ->whereColumn('reference.run_id', 'message.run_id')
                            ->whereColumn('reference.hash', 'message.hash')
                            ->where('record.occurred_at', '<=', $cutoff->format('Y-m-d H:i:s.uP'));
                    })
                    ->orderBy('message.id')->limit($this->batchSize())->pluck('message.id'),
                'run_flag' => DB::table($store->table.' as flag')
                    ->join('assay_runs as run', 'run.id', '=', 'flag.run_id')
                    ->whereIn('flag.run_id', $runIds)
                    ->whereRaw('COALESCE(run.ended_at, run.started_at, run.earliest_received_at) <= ?', [$cutoff->format('Y-m-d H:i:s.uP')])
                    ->orderBy('flag.run_id')->limit($this->batchSize())->pluck('flag.run_id'),
                'dataset' => DB::table($store->table)->where('app_id', $appId)
                    ->whereIn('subject_tombstone', array_filter($subjects, static fn (string $subject): bool => str_starts_with($subject, 'deleted:')))
                    ->where('source_occurred_at', '<=', $cutoff->format('Y-m-d H:i:s.uP'))
                    ->orderBy('id')->limit($this->batchSize())->pluck('id'),
                'pending_target' => DB::table($store->table.' as pending')
                    ->where('pending.app_id', $appId)
                    ->where('pending.occurred_at', '<=', $cutoff->format('Y-m-d H:i:s.uP'))
                    ->where(function (Builder $query) use ($runIds): void {
                        $query->whereExists(function (Builder $query) use ($runIds): void {
                            $query->selectRaw('1')->from('assay_records as target')
                                ->whereColumn('target.app_id', 'pending.app_id')
                                ->whereColumn('target.record_id', 'pending.target_record_id')
                                ->whereIn('target.run_id', $runIds);
                        })->orWhereExists(function (Builder $query) use ($runIds): void {
                            $query->selectRaw('1')->from('assay_runs as run')
                                ->whereColumn('run.app_id', 'pending.app_id')
                                ->whereColumn('run.invocation_id', 'pending.invocation_id')
                                ->whereIn('run.id', $runIds);
                        });
                    })
                    ->orderBy('pending.id')->limit($this->batchSize())->pluck('pending.id'),
                default => collect(),
            };

            if ($ids->isNotEmpty()) {
                if ($store->erasure === 'pending_target') {
                    $receiptIds = DB::table($store->table)->whereIn('id', $ids)->pluck('receipt_id');
                    DB::table('assay_content_attach_receipts')->whereIn('id', $receiptIds)->update([
                        'status' => 'rejected',
                        'reason' => 'erased_subject',
                    ]);
                }

                $key = $store->erasure === 'run_flag' ? 'run_id' : 'id';
                $count = DB::table($store->table)->whereIn($key, $ids)->delete();
                $deleted += $count;
            }
        } while ($ids->isNotEmpty());

        return $deleted;
    }

    private function databaseNow(): CarbonImmutable
    {
        /** @var stdClass $row */
        $row = DB::selectOne('SELECT CURRENT_TIMESTAMP AS current_time');

        return CarbonImmutable::parse((string) $row->current_time);
    }

    private function batchSize(): int
    {
        $batchSize = config('assay.erasure.batch_size');

        if (! is_int($batchSize) || $batchSize < 1) {
            throw new RuntimeException('The erasure batch size must be a positive integer.');
        }

        return $batchSize;
    }

    private function assertAppExists(string $appId): void
    {
        if (! DB::table('assay_apps')->where('id', $appId)->exists()) {
            throw new RuntimeException('The erasure app does not exist.');
        }
    }
}
