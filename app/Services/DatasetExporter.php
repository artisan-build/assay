<?php

declare(strict_types=1);

namespace App\Services;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class DatasetExporter
{
    public function __construct(private SubjectErasureBarrier $barrier) {}

    /** @return array{id: string, download_url: string} */
    public function request(string $datasetId, ActingPrincipal $principal): array
    {
        if (! DB::table('assay_datasets')->where('id', $datasetId)->exists()) {
            throw new NotFoundHttpException;
        }

        $id = (string) Str::uuid();
        DB::table('assay_export_requests')->insert([
            'id' => $id,
            'dataset_id' => $datasetId,
            'requested_by' => $this->principalId($principal),
            'requested_at' => $this->databaseNow()->format('Y-m-d H:i:s.uP'),
        ]);

        return ['id' => $id, 'download_url' => route('assay.exports.download', ['export' => $id], false)];
    }

    public function render(string $requestId, ActingPrincipal $principal): string
    {
        return DB::transaction(function () use ($requestId, $principal): string {
            /** @var stdClass|null $request */
            $request = DB::table('assay_export_requests')->where('id', $requestId)->lockForUpdate()->first();

            if ($request === null) {
                throw new NotFoundHttpException;
            }

            if (! hash_equals((string) $request->requested_by, $this->principalId($principal))) {
                throw new AuthorizationException('The export request belongs to another principal.');
            }

            if (CarbonImmutable::parse((string) $request->requested_at)->addMinutes(15)->lessThan($this->databaseNow())) {
                throw new GoneHttpException('The export request has expired.');
            }

            $items = DB::table('assay_dataset_items')->where('dataset_id', $request->dataset_id)
                ->oldest('added_at')->orderBy('id')->get();

            foreach ($items as $item) {
                if (is_string($item->subject_tombstone) && $item->subject_tombstone !== '') {
                    $this->barrier->lock((string) $item->app_id, $item->subject_tombstone);
                }
            }

            $items = DB::table('assay_dataset_items')->where('dataset_id', $request->dataset_id)
                ->oldest('added_at')->orderBy('id')->get(['id', 'snapshot']);
            $lines = [];

            foreach ($items as $item) {
                /** @var array<string, mixed> $snapshot */
                $snapshot = json_decode((string) $item->snapshot, true, flags: JSON_THROW_ON_ERROR);
                $lines[] = json_encode([
                    'schema' => 'assay.dataset-item.v1',
                    'item_id' => (string) $item->id,
                    ...$snapshot,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            return $lines === [] ? '' : implode("\n", $lines)."\n";
        }, 3);
    }

    private function principalId(ActingPrincipal $principal): string
    {
        $identifier = $principal->identifier();

        if (! is_int($identifier) && ! is_string($identifier)) {
            throw new AuthorizationException('An authenticated principal is required.');
        }

        return (string) $identifier;
    }

    private function databaseNow(): CarbonImmutable
    {
        /** @var stdClass $row */
        $row = DB::selectOne('SELECT CURRENT_TIMESTAMP AS current_time');

        return CarbonImmutable::parse((string) $row->current_time);
    }
}
