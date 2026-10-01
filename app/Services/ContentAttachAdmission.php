<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ContentPersistenceFailed;
use ArtisanBuild\AssayContracts\Content;
use ArtisanBuild\AssayContracts\InvalidEnvelope;
use ArtisanBuild\AssayContracts\Operation;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use PDOException;
use stdClass;

final readonly class ContentAttachAdmission
{
    public function __construct(
        private ContentPersistence $content,
        private ContentStoreRegistry $stores,
    ) {}

    public function rejectMetadataSmuggling(string $appId, string $receivedAt, string $recordId): void
    {
        DB::table('assay_content_attach_receipts')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'app_id' => $appId,
            'record_id' => $recordId,
            'status' => 'rejected',
            'reason' => 'metadata_smuggling',
            'received_at' => $receivedAt,
        ]);
    }

    /** @param array<string, mixed> $attach */
    public function receive(string $appId, string $receivedAt, array $attach): string
    {
        try {
            return DB::transaction(fn (): string => $this->receiveTransaction($appId, $receivedAt, $attach));
        } catch (ContentPersistenceFailed|PDOException) {
            $this->rejectAfterPersistenceFailure($appId, (string) $attach['record_id'], $receivedAt);

            return 'persistence_failed';
        }
    }

    public function applyForTarget(string $appId, string $targetRowId, string $asOf): void
    {
        $pendingIds = DB::table($this->stores->pendingContentAttaches())
            ->where('app_id', $appId)
            ->where('target_record_id', DB::table('assay_records')->where('id', $targetRowId)->value('record_id'))
            ->oldest('id')
            ->pluck('id');

        foreach ($pendingIds as $pendingId) {
            $this->processPending((int) $pendingId, CarbonImmutable::parse($asOf));
        }
    }

    /** @return array{applied: int, dropped: int} */
    public function reconcilePending(?CarbonImmutable $asOf = null, ?int $limit = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $limit ??= max(1, (int) config('assay.content_attach.batch_size', 1_000));
        $pendingIds = DB::table($this->stores->pendingContentAttaches().' as pending')
            ->where(function ($query) use ($asOf): void {
                $query->where('pending.expires_at', '<=', $asOf->format('Y-m-d H:i:s.uP'))
                    ->orWhereExists(function ($target): void {
                        $target->selectRaw('1')
                            ->from('assay_records as target')
                            ->whereColumn('target.app_id', 'pending.app_id')
                            ->whereColumn('target.record_id', 'pending.target_record_id');
                    });
            })
            ->oldest('pending.expires_at')
            ->orderBy('pending.id')
            ->limit($limit)
            ->pluck('pending.id');
        $result = ['applied' => 0, 'dropped' => 0];

        foreach ($pendingIds as $pendingId) {
            $status = $this->processPending((int) $pendingId, $asOf);

            if ($status === 'accepted') {
                $result['applied']++;
            } elseif ($status === 'orphan_expired') {
                $result['dropped']++;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $attach */
    private function receiveTransaction(string $appId, string $receivedAt, array $attach): string
    {
        $receiptId = (string) Str::uuid();
        $inserted = DB::table('assay_content_attach_receipts')->insertOrIgnore([
            'id' => $receiptId,
            'app_id' => $appId,
            'record_id' => $attach['record_id'],
            'status' => 'received',
            'received_at' => $receivedAt,
        ]);

        if ($inserted === 0) {
            return 'duplicate';
        }

        $target = DB::table('assay_records')
            ->where('app_id', $appId)
            ->where('record_id', $attach['target_record_id'])
            ->lockForUpdate()
            ->first();

        if ($target !== null) {
            $status = $this->admit($target, $attach);
            $this->finishReceipt($receiptId, $status);

            return $status;
        }

        if (DB::table('assay_records')->where('record_id', $attach['target_record_id'])->exists()) {
            $this->finishReceipt($receiptId, 'cross_app');

            return 'cross_app';
        }

        $received = CarbonImmutable::parse($receivedAt);
        $expires = $received->addMinutes(max(1, (int) config('assay.stale_after_minutes', 30)));
        DB::table($this->stores->pendingContentAttaches())->insert([
            'receipt_id' => $receiptId,
            'app_id' => $appId,
            'target_record_id' => $attach['target_record_id'],
            'invocation_id' => $attach['invocation_id'],
            'occurred_at' => $attach['at'],
            'received_at' => $receivedAt,
            'expires_at' => $expires->format('Y-m-d H:i:s.uP'),
            'content' => $this->contentJson($attach['content']),
        ]);
        DB::table('assay_content_attach_receipts')->where('id', $receiptId)->update(['status' => 'pending']);

        return 'pending';
    }

    /**
     * This is the sole target-content admission point. PR6c adds its erasure barrier here.
     *
     * @param  array<string, mixed>  $attach
     */
    private function admit(stdClass $target, array $attach): string
    {
        if ($target->invocation_id !== $attach['invocation_id']) {
            return 'cross_invocation';
        }

        if ($target->run_id === null || $target->type === RecordType::ContentAttach->value) {
            return 'invalid_target';
        }

        if (DB::table($this->stores->recordContent())->where('record_id', $target->id)->exists()) {
            return 'target_has_content';
        }

        try {
            $content = Content::fromValue(json_decode($this->contentJson($attach['content']), false, 512, JSON_THROW_ON_ERROR));
            $type = RecordType::from((string) $target->type);
            $operation = $target->operation === null ? null : Operation::tryFrom((string) $target->operation);
            $outcome = $target->outcome === null ? null : Outcome::tryFrom((string) $target->outcome);
            $content->validateFor($type, $operation, $outcome);
        } catch (InvalidEnvelope|JsonException|\ValueError) {
            return 'invalid_content';
        }

        $this->content->persist(
            recordId: (string) $target->id,
            runId: (string) $target->run_id,
            sourceRecordId: (string) $attach['record_id'],
            record: [
                'type' => $target->type,
                'content' => $content->toJson(),
            ],
        );

        return 'accepted';
    }

    private function processPending(int $pendingId, CarbonImmutable $asOf): string
    {
        try {
            return DB::transaction(function () use ($pendingId, $asOf): string {
                /** @var stdClass|null $pending */
                $pending = DB::table($this->stores->pendingContentAttaches())->where('id', $pendingId)->lockForUpdate()->first();

                if ($pending === null) {
                    return 'missing';
                }

                if ($asOf->greaterThanOrEqualTo(CarbonImmutable::parse((string) $pending->expires_at))) {
                    DB::table('assay_apps')->where('id', $pending->app_id)->increment('dropped_content_attach_total');
                    $this->finishReceipt((string) $pending->receipt_id, 'orphan_expired');
                    DB::table($this->stores->pendingContentAttaches())->where('id', $pendingId)->delete();

                    return 'orphan_expired';
                }

                /** @var stdClass|null $target */
                $target = DB::table('assay_records')
                    ->where('app_id', $pending->app_id)
                    ->where('record_id', $pending->target_record_id)
                    ->lockForUpdate()
                    ->first();

                if ($target === null) {
                    return 'pending';
                }

                $attach = [
                    'record_id' => DB::table('assay_content_attach_receipts')->where('id', $pending->receipt_id)->value('record_id'),
                    'target_record_id' => $pending->target_record_id,
                    'invocation_id' => $pending->invocation_id,
                    'at' => $pending->occurred_at,
                    'content' => $pending->content,
                ];
                $status = $this->admit($target, $attach);
                $this->finishReceipt((string) $pending->receipt_id, $status);
                DB::table($this->stores->pendingContentAttaches())->where('id', $pendingId)->delete();

                return $status;
            });
        } catch (ContentPersistenceFailed|PDOException) {
            /** @var stdClass|null $pending */
            $pending = DB::table($this->stores->pendingContentAttaches())->where('id', $pendingId)->first();

            if ($pending !== null) {
                $recordId = (string) DB::table('assay_content_attach_receipts')->where('id', $pending->receipt_id)->value('record_id');
                $this->finishReceipt((string) $pending->receipt_id, 'persistence_failed');
                DB::table($this->stores->pendingContentAttaches())->where('id', $pendingId)->delete();
                $this->logPersistenceFailure($recordId);
            }

            return 'persistence_failed';
        }
    }

    private function finishReceipt(string $receiptId, string $status): void
    {
        DB::table('assay_content_attach_receipts')->where('id', $receiptId)->update([
            'status' => $status === 'accepted' ? 'accepted' : 'rejected',
            'reason' => $status === 'accepted' ? null : $status,
        ]);
    }

    private function rejectAfterPersistenceFailure(string $appId, string $recordId, string $receivedAt): void
    {
        DB::table('assay_content_attach_receipts')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'app_id' => $appId,
            'record_id' => $recordId,
            'status' => 'rejected',
            'reason' => 'persistence_failed',
            'received_at' => $receivedAt,
        ]);
        $this->logPersistenceFailure($recordId);
    }

    private function logPersistenceFailure(string $recordId): void
    {
        Log::warning('Content attach rejected.', [
            'reason' => 'persistence_failed',
            'record_id' => $recordId,
        ]);
    }

    private function contentJson(mixed $content): string
    {
        return is_string($content) ? $content : json_encode($content, JSON_THROW_ON_ERROR);
    }
}
