<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\EffectiveAccess;
use App\Data\DashboardTable;
use App\Enums\UsageMetric;
use App\Http\Middleware\EnsureAssayAccess;
use App\Services\UsageDashboard;
use App\Support\DashboardCsvSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class UsageDashboardController extends Controller
{
    private const array OPERATIONS = [
        'agent',
        'embeddings',
        'image',
        'audio',
        'transcription',
        'reranking',
        'classification',
    ];

    public function __construct(
        private readonly UsageDashboard $dashboard,
        private readonly DashboardCsvSerializer $csv,
    ) {}

    public function index(Request $request): View
    {
        $decision = EnsureAssayAccess::decision($request);
        $tables = [];

        foreach ($this->tableNames() as $table) {
            $tables[$table] = $this->table($request, $table, $decision);
        }

        return view('dashboard', [
            'tables' => $tables,
            'decision' => $decision,
            'metrics' => UsageMetric::cases(),
            'selectedMetric' => $this->metric($request),
        ]);
    }

    public function show(Request $request, string $table): JsonResponse
    {
        return response()->json($this->table(
            $request,
            $table,
            EnsureAssayAccess::decision($request),
        ));
    }

    public function csv(Request $request, string $table): Response
    {
        $csv = $this->csv->serialize($this->table(
            $request,
            $table,
            EnsureAssayAccess::decision($request),
        ));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="assay-'.$table.'.csv"',
        ]);
    }

    public function runTree(Request $request, string $run): JsonResponse
    {
        EnsureAssayAccess::decision($request);
        $table = $this->dashboard->runTree($run);

        abort_if($table['rows'] === [], 404);

        return response()->json($table);
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        return [
            'usage-over-time',
            'usage-by-agent',
            'usage-by-subject',
            'top-runs',
            'reliability',
            'latency',
            'pipeline-health',
        ];
    }

    private function table(Request $request, string $table, EffectiveAccess $decision): DashboardTable
    {
        return match ($table) {
            'usage-over-time' => $this->dashboard->usageOverTime(
                $this->metric($request),
                $this->filter($request, 'app'),
                $this->filter($request, 'environment'),
                $this->operation($request),
            ),
            'usage-by-agent' => $this->dashboard->usageByAgent($this->metric($request)),
            'usage-by-subject' => $this->dashboard->usageBySubject(
                $this->metric($request),
                $this->limit($request),
            ),
            'top-runs' => $this->dashboard->topRuns(
                $this->metric($request),
                $decision->content,
                $this->limit($request),
            ),
            'reliability' => $this->dashboard->reliability(),
            'latency' => $this->dashboard->latency(),
            'pipeline-health' => $this->dashboard->pipelineHealth($this->filter($request, 'app')),
            default => abort(404),
        };
    }

    private function metric(Request $request): UsageMetric
    {
        $validated = $request->validate([
            'metric' => ['sometimes', 'string', Rule::enum(UsageMetric::class)],
        ]);

        return UsageMetric::from($validated['metric'] ?? UsageMetric::InputTokens->value);
    }

    private function limit(Request $request): int
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return (int) ($validated['limit'] ?? 25);
    }

    private function filter(Request $request, string $name): ?string
    {
        $validated = $request->validate([
            $name => ['sometimes', 'string', 'max:255'],
        ]);

        return $validated[$name] ?? null;
    }

    private function operation(Request $request): ?string
    {
        $validated = $request->validate([
            'operation' => ['sometimes', 'string', Rule::in(self::OPERATIONS)],
        ]);

        return $validated['operation'] ?? null;
    }
}
