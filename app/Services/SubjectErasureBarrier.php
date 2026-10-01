<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class SubjectErasureBarrier
{
    public function __construct(private SubjectErasureHasher $hasher) {}

    /** @param array<string, mixed> $record */
    public function allowsRecord(string $appId, array $record): bool
    {
        $subject = $this->recordSubject($appId, $record);

        if ($subject === null) {
            return true;
        }

        $this->lock($appId, $subject);

        return $this->allows($appId, $subject, CarbonImmutable::parse((string) $record['at']));
    }

    public function allowsRun(string $appId, string $runId, CarbonImmutable $occurredAt): bool
    {
        return $this->runAdmission($appId, $runId, $occurredAt)['allowed'];
    }

    /** @return array{allowed: bool, subject: string|null} */
    public function runAdmission(string $appId, string $runId, CarbonImmutable $occurredAt): array
    {
        $subject = $this->effectiveSubject($appId, $runId);

        if (! is_string($subject) || $subject === '') {
            return ['allowed' => true, 'subject' => null];
        }

        $this->lock($appId, $subject);

        return ['allowed' => $this->allows($appId, $subject, $occurredAt), 'subject' => $subject];
    }

    public function lock(string $appId, string $subject): void
    {
        foreach ($this->hasher->lockTokens($subject) as $token) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$appId."\0".$token]);
        }
    }

    public function find(string $appId, string $subject): ?stdClass
    {
        return $this->recordQuery($appId, $subject)->first();
    }

    private function allows(string $appId, string $subject, CarbonImmutable $occurredAt): bool
    {
        $erasure = $this->find($appId, $subject);

        return $erasure === null
            || $occurredAt->greaterThan(CarbonImmutable::parse((string) $erasure->cutoff_at));
    }

    /** @param array<string, mixed> $record */
    private function recordSubject(string $appId, array $record): ?string
    {
        if (isset($record['subject']) && is_string($record['subject']) && $record['subject'] !== '') {
            return $record['subject'];
        }

        foreach (['invocation_id', 'parent_invocation_id'] as $field) {
            if (! isset($record[$field]) || ! is_string($record[$field])) {
                continue;
            }

            $runId = DB::table('assay_runs')
                ->where('app_id', $appId)
                ->where('invocation_id', $record[$field])
                ->value('id');

            if (! is_string($runId) || $runId === '') {
                continue;
            }

            $subject = $this->effectiveSubject($appId, $runId);

            if (is_string($subject) && $subject !== '') {
                return $subject;
            }
        }

        return null;
    }

    private function effectiveSubject(string $appId, string $runId): ?string
    {
        /** @var stdClass|null $row */
        $row = DB::selectOne(<<<'SQL'
            WITH RECURSIVE ancestry AS (
                SELECT id, parent_run_id, subject, 0 AS depth, ARRAY[id] AS path
                FROM assay_runs
                WHERE app_id = ? AND id = ?
                UNION ALL
                SELECT parent.id, parent.parent_run_id, parent.subject, child.depth + 1, child.path || parent.id
                FROM assay_runs AS parent
                JOIN ancestry AS child ON child.parent_run_id = parent.id
                WHERE parent.app_id = ? AND NOT parent.id = ANY(child.path)
            )
            SELECT subject
            FROM ancestry
            WHERE subject IS NOT NULL AND subject != ''
            ORDER BY depth
            LIMIT 1
            SQL, [$appId, $runId, $appId]);

        return $row !== null && is_string($row->subject) && $row->subject !== ''
            ? $row->subject
            : null;
    }

    private function recordQuery(string $appId, string $subject): Builder
    {
        $query = DB::table('assay_erasure_records')->where('app_id', $appId);

        if (str_starts_with($subject, 'deleted:')) {
            return $query->where('tombstone', $subject);
        }

        $candidates = $this->hasher->candidates($subject);

        return $query->where(function (Builder $query) use ($candidates): void {
            foreach ($candidates as $candidate) {
                $query->orWhere(function (Builder $query) use ($candidate): void {
                    $query->where('key_version', $candidate['version'])
                        ->where('lookup_key', $candidate['lookup_key']);
                });
            }
        });
    }
}
