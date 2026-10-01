<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureAssayAccess;
use App\Services\DatasetExporter;
use App\Services\DatasetManager;
use App\Services\RunCuration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class CurationController extends Controller
{
    public function __construct(
        private readonly RunCuration $curation,
        private readonly DatasetManager $datasets,
        private readonly DatasetExporter $exports,
    ) {}

    public function run(string $run): View
    {
        return view('curation-run', ['run' => $run, 'flag' => $this->curation->get($run)]);
    }

    public function flag(Request $request, string $run): JsonResponse
    {
        $validated = $request->validate([
            'labels' => ['present', 'array', 'max:25'],
            'labels.*' => ['string', 'max:100'],
            'rating' => ['nullable', Rule::in(['pass', 'fail', 1, 2, 3, 4, 5, '1', '2', '3', '4', '5'])],
            'note' => ['nullable', 'string', 'max:10000'],
        ]);
        /** @var list<string> $labels */
        $labels = $validated['labels'];
        /** @var int|string|null $rating */
        $rating = $validated['rating'] ?? null;

        return response()->json($this->curation->flag($run, $labels, $rating, $validated['note'] ?? null));
    }

    public function datasets(): View
    {
        return view('datasets', ['datasets' => $this->datasets->datasets()]);
    }

    public function createDataset(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'retention_days' => ['sometimes', 'nullable', 'integer', 'between:1,36500'],
        ]);
        $dataset = $this->datasets->create(
            $validated['name'],
            array_key_exists('retention_days', $validated) ? $validated['retention_days'] : false,
        );

        return $request->expectsJson()
            ? response()->json($dataset, 201)
            : to_route('assay.datasets.show', ['dataset' => $dataset['id']]);
    }

    public function dataset(string $dataset): View
    {
        return view('dataset', ['dataset' => $this->datasets->dataset($dataset)]);
    }

    public function addItem(Request $request, string $dataset): JsonResponse
    {
        $validated = $request->validate(['run_id' => ['required', 'uuid']]);

        return response()->json($this->datasets->add($dataset, $validated['run_id']), 201);
    }

    public function requestExport(Request $request, string $dataset): JsonResponse
    {
        return response()->json(
            $this->exports->request($dataset, EnsureAssayAccess::principal($request)),
            201,
        );
    }

    public function download(Request $request, string $export): Response
    {
        $jsonl = $this->exports->render($export, EnsureAssayAccess::principal($request));

        return response($jsonl, 200, [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="assay-dataset-v1.jsonl"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
