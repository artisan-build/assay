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
        $subject = DB::table('assay_runs')
            ->where('app_id', $appId)
            ->where('id', $runId)
            ->value('subject');

        if (! is_string($subject) || $subject === '') {
            return true;
        }

        $this->lock($appId, $subject);

        return $this->allows($appId, $subject, $occurredAt);
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

            $subject = DB::table('assay_runs')
                ->where('app_id', $appId)
                ->where('invocation_id', $record[$field])
                ->value('subject');

            if (is_string($subject) && $subject !== '') {
                return $subject;
            }
        }

        return null;
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
