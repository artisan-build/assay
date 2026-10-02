<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class DatasetManager
{
    public function __construct(
        private SubjectErasureBarrier $barrier,
        private SubjectErasureHasher $hasher,
    ) {}

    /** @return array{id: string, name: string, retention_days: int|null} */
    public function create(string $name, int|null|false $retentionDays = false): array
    {
        $days = $retentionDays === false ? config('assay.retention.dataset_days') : $retentionDays;

        if ($days !== null && (! is_int($days) || $days < 1)) {
            throw new RuntimeException('Dataset retention must be a positive integer or null.');
        }

        $id = (string) Str::uuid();
        DB::table('assay_datasets')->insert([
            'id' => $id,
            'name' => $name,
            'retention_days' => $days,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'name' => $name, 'retention_days' => $days];
    }

    /** @return array{id: string, dataset_id: string, snapshot: array<string, mixed>, added_at: string, expires_at: string|null} */
    public function add(string $datasetId, string $runId): array
    {
        return DB::transaction(function () use ($datasetId, $runId): array {
            /** @var stdClass|null $dataset */
            $dataset = DB::table('assay_datasets')->where('id', $datasetId)->lockForUpdate()->first();
            /** @var stdClass|null $run */
            $run = DB::table('assay_runs')->where('id', $runId)->first();

            if ($dataset === null || $run === null) {
                throw new NotFoundHttpException;
            }

            if ((bool) $run->content_incomplete) {
                throw new ConflictHttpException('Content-incomplete runs cannot be added to a dataset.');
            }

            $occurredAt = CarbonImmutable::parse((string) ($run->ended_at ?? $run->started_at ?? $run->earliest_received_at));

            $admission = $this->barrier->runAdmission((string) $run->app_id, $runId, $occurredAt);

            if (! $admission['allowed']) {
                throw new AuthorizationException('The source run is behind an erasure barrier.');
            }

            $subject = $admission['subject'];
            $identity = $subject === null || str_starts_with($subject, 'deleted:')
                ? null
                : $this->hasher->active($subject);
            $addedAt = $this->databaseNow();
            $retentionDays = $dataset->retention_days;
            $expiresAt = is_int($retentionDays)
                ? $addedAt->addDays($retentionDays)
                : (is_numeric($retentionDays) ? $addedAt->addDays((int) $retentionDays) : null);
            $snapshot = $this->snapshot($runId, $run);
            $id = (string) Str::uuid();

            DB::table('assay_dataset_items')->insert([
                'id' => $id,
                'dataset_id' => $datasetId,
                'app_id' => $run->app_id,
                'source_run_id' => $runId,
                'subject_key_version' => $identity['version'] ?? null,
                'subject_tombstone' => $identity['tombstone'] ?? ($subject !== null && str_starts_with($subject, 'deleted:') ? $subject : null),
                'source_occurred_at' => $occurredAt->format('Y-m-d H:i:s.uP'),
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'added_at' => $addedAt->format('Y-m-d H:i:s.uP'),
                'expires_at' => $expiresAt?->format('Y-m-d H:i:s.uP'),
            ]);

            return [
                'id' => $id,
                'dataset_id' => $datasetId,
                'snapshot' => $snapshot,
                'added_at' => $addedAt->format('Y-m-d\TH:i:s.uP'),
                'expires_at' => $expiresAt?->format('Y-m-d\TH:i:s.uP'),
            ];
        }, 3);
    }

    /** @return list<array{id: string, name: string, retention_days: int|null, item_count: int}> */
    public function datasets(int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10000, $offset));

        return DB::table('assay_datasets as dataset')
            ->leftJoin('assay_dataset_items as item', 'item.dataset_id', '=', 'dataset.id')
            ->groupBy('dataset.id')
            ->orderBy('dataset.name')->orderBy('dataset.id')
            ->limit($limit)->offset($offset)
            ->get(['dataset.id', 'dataset.name', 'dataset.retention_days', DB::raw('COUNT(item.id) AS item_count')])
            ->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'retention_days' => $row->retention_days === null ? null : (int) $row->retention_days,
                'item_count' => (int) $row->item_count,
            ])->all();
    }

    /** @return array{id: string, name: string, retention_days: int|null, items: list<array{id: string, snapshot: array<string, mixed>}>} */
    public function dataset(string $id): array
    {
        /** @var stdClass|null $dataset */
        $dataset = DB::table('assay_datasets')->where('id', $id)->first();

        if ($dataset === null) {
            throw new NotFoundHttpException;
        }

        $items = DB::table('assay_dataset_items')->where('dataset_id', $id)
            ->oldest('added_at')->orderBy('id')->get(['id', 'snapshot'])
            ->map(static function (stdClass $item): array {
                /** @var array<string, mixed> $snapshot */
                $snapshot = json_decode((string) $item->snapshot, true, flags: JSON_THROW_ON_ERROR);

                return ['id' => (string) $item->id, 'snapshot' => $snapshot];
            })->all();

        return [
            'id' => (string) $dataset->id,
            'name' => (string) $dataset->name,
            'retention_days' => $dataset->retention_days === null ? null : (int) $dataset->retention_days,
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(string $runId, stdClass $run): array
    {
        $start = $this->recordContent($runId, 'run.start', false);
        $end = $this->recordContent($runId, 'step.end', true);
        /** @var stdClass|null $flag */
        $flag = DB::table('assay_run_flags')->where('run_id', $runId)->first();
        /** @var list<string> $labels */
        $labels = json_decode((string) ($flag->labels ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
        $rating = $flag->rating ?? null;

        if (is_string($rating) && ctype_digit($rating)) {
            $rating = (int) $rating;
        }

        $observed = [
            'text' => $end['output_text'] ?? null,
            'structured' => $end['structured_output'] ?? null,
            'tool_calls' => $end['tool_calls'] ?? [],
        ];

        return [
            'agent_class' => $run->agent,
            'provider' => $run->provider,
            'requested_model' => $run->requested_model,
            'instructions' => $start['instructions'] ?? null,
            'input_messages' => $this->inputMessages($runId),
            'tool_schemas' => $start['tools'] ?? [],
            'observed_output' => $observed,
            'labels' => $labels,
            'rating' => is_int($rating) || is_string($rating) ? $rating : null,
            'note' => is_string($flag->note ?? null) ? $flag->note : null,
            'source_client_version' => $run->client_version,
            'deploy' => $run->deploy,
            'capture' => $run->capture,
            'sampled' => $run->sampled === null ? null : (bool) $run->sampled,
            'hook_applied' => true,
            'replay_fidelity' => $run->replay_inputs_omitted === null ? 'complete' : 'partial',
        ];
    }

    /** @return array<string, mixed> */
    private function recordContent(string $runId, string $type, bool $latest): array
    {
        $query = DB::table('assay_record_content as content')
            ->join('assay_records as record', 'record.id', '=', 'content.record_id')
            ->where('content.run_id', $runId)->where('record.type', $type);
        $latest ? $query->latest('record.occurred_at')->orderByDesc('record.id') : $query->oldest('record.occurred_at')->orderBy('record.id');
        $encoded = $query->value('content.content');

        if (! is_string($encoded)) {
            return [];
        }

        /** @var array<string, mixed> $content */
        $content = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        return $content;
    }

    /** @return list<array<string, mixed>> */
    private function inputMessages(string $runId): array
    {
        $recordId = DB::table('assay_records')
            ->where('run_id', $runId)->where('type', 'step.start')
            ->latest('occurred_at')->orderByDesc('id')->value('id');

        if (! is_string($recordId)) {
            return [];
        }

        return DB::table('assay_message_references as reference')
            ->join('assay_messages as message', function ($join): void {
                $join->on('message.run_id', '=', 'reference.run_id')->on('message.hash', '=', 'reference.hash');
            })
            ->where('reference.record_id', $recordId)->orderBy('reference.position')
            ->pluck('message.body')->map(static function (mixed $body): array {
                /** @var array<string, mixed> $message */
                $message = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);

                return $message;
            })->all();
    }

    private function databaseNow(): CarbonImmutable
    {
        /** @var stdClass $row */
        $row = DB::selectOne('SELECT CURRENT_TIMESTAMP AS current_time');

        return CarbonImmutable::parse((string) $row->current_time);
    }
}
