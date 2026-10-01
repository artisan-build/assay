<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class RunCuration
{
    public function __construct(private SubjectErasureBarrier $barrier) {}

    /**
     * @param  list<string>  $labels
     * @return array{labels: list<string>, rating: int|string|null, note: string|null}
     */
    public function flag(string $runId, array $labels, int|string|null $rating, ?string $note): array
    {
        return DB::transaction(function () use ($runId, $labels, $rating, $note): array {
            $run = DB::table('assay_runs')->where('id', $runId)->lockForUpdate()->first();

            if ($run === null) {
                throw new NotFoundHttpException;
            }

            $occurredAt = CarbonImmutable::parse((string) ($run->ended_at ?? $run->started_at ?? $run->earliest_received_at));

            if (! $this->barrier->allowsRun((string) $run->app_id, $runId, $occurredAt)) {
                throw new AuthorizationException('The source run is behind an erasure barrier.');
            }

            $labels = array_values(array_unique($labels));
            $storedRating = $rating === null ? null : (string) $rating;

            DB::table('assay_run_flags')->upsert([[
                'run_id' => $runId,
                'labels' => json_encode($labels, JSON_THROW_ON_ERROR),
                'rating' => $storedRating,
                'note' => $note,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['run_id'], ['labels', 'rating', 'note', 'updated_at']);

            return ['labels' => $labels, 'rating' => $rating, 'note' => $note];
        });
    }

    /** @return array{labels: list<string>, rating: int|string|null, note: string|null} */
    public function get(string $runId): array
    {
        $flag = $this->query()->where('run.id', $runId)->first();

        if ($flag === null) {
            throw new NotFoundHttpException;
        }

        /** @var list<string> $labels */
        $labels = json_decode((string) ($flag->labels ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
        $rating = $flag->rating ?? null;

        if (is_string($rating) && ctype_digit($rating)) {
            $rating = (int) $rating;
        }

        return [
            'labels' => $labels,
            'rating' => is_int($rating) || is_string($rating) ? $rating : null,
            'note' => is_string($flag->note ?? null) ? $flag->note : null,
        ];
    }

    public function query(): Builder
    {
        return DB::table('assay_runs as run')
            ->leftJoin('assay_run_flags as flag', 'flag.run_id', '=', 'run.id')
            ->select(['run.id', 'run.invocation_id', 'run.agent', 'flag.labels', 'flag.rating', 'flag.note']);
    }
}
