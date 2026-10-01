<?php

declare(strict_types=1);

use App\Jobs\ProcessUsageEnvelope;
use App\Services\UsageIngestProcessor;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Support\EnvelopeFactory;

/** @return list<array<string, string>> */
function csvRows(TestResponse $response): array
{
    $lines = preg_split('/\r\n/', trim((string) $response->getContent()));
    $header = str_getcsv((string) array_shift($lines), escape: '');

    return array_map(static function (string $line) use ($header): array {
        $values = str_getcsv($line, escape: '');

        return array_combine($header, $values);
    }, array_values(array_filter($lines, static fn (string $line): bool => $line !== '')));
}

function dashboardOwner(): User
{
    $user = User::query()->create([
        'name' => 'Dashboard Owner',
        'email' => 'dashboard-owner@example.test',
    ]);
    $user->forceFill(['role' => UserRole::Owner->value])->save();

    return $user;
}

function seedDashboardData(): void
{
    $formulaRecords = [
        EnvelopeFactory::record([
            'type' => 'run.start',
            'invocation_id' => '=agent-zero',
            'at' => '2026-10-01T12:00:00.000000+00:00',
            'agent' => '=FormulaAgent',
            'subject' => '+subject-zero',
            'model' => ['provider' => '=provider-a', 'requested' => 'requested-a'],
        ]),
        EnvelopeFactory::record([
            'type' => 'step.end',
            'invocation_id' => '=agent-zero',
            'at' => '2026-10-01T12:00:01.000000+00:00',
            'step' => 0,
            'duration_ms' => 10.0,
            'usage' => ['input_tokens' => 0],
            'model' => ['provider' => '=provider-a', 'requested' => 'requested-a', 'responded' => 'responded-a'],
        ]),
        EnvelopeFactory::record([
            'type' => 'run.end',
            'invocation_id' => '=agent-zero',
            'at' => '2026-10-01T12:00:02.000000+00:00',
            'outcome' => 'completed',
            'usage' => ['input_tokens' => 0],
            'model' => ['provider' => '=provider-a', 'requested' => 'requested-a', 'responded' => 'responded-a'],
        ]),
        EnvelopeFactory::record([
            'operation' => 'reranking',
            'invocation_id' => 'fractional-search',
            'attempt' => null,
            'at' => '2026-10-01T12:03:30.000000+00:00',
            'subject' => '+subject-search',
            'model' => ['provider' => 'provider-search', 'requested' => 'search-requested'],
        ]),
        EnvelopeFactory::record([
            'type' => 'run.end',
            'operation' => 'reranking',
            'invocation_id' => 'fractional-search',
            'attempt' => null,
            'at' => '2026-10-01T12:03:30.015000+00:00',
            'outcome' => 'completed',
            'usage' => ['search_units' => 0.125],
            'model' => ['provider' => 'provider-search', 'requested' => 'search-requested'],
        ]),
        EnvelopeFactory::record([
            'type' => 'run.start',
            'invocation_id' => 'agent-failed',
            'at' => '2026-10-01T12:01:00.000000+00:00',
            'agent' => '=FormulaAgent',
            'model' => ['provider' => '=provider-a', 'requested' => 'requested-only'],
        ]),
        EnvelopeFactory::record([
            'type' => 'step.end',
            'invocation_id' => 'agent-failed',
            'at' => '2026-10-01T12:01:01.000000+00:00',
            'step' => 0,
            'duration_ms' => 30.0,
            'usage' => ['input_tokens' => 10],
            'model' => ['provider' => '=provider-a', 'requested' => 'requested-only'],
        ]),
        EnvelopeFactory::record([
            'type' => 'step.fail',
            'invocation_id' => 'agent-failed',
            'at' => '2026-10-01T12:01:02.000000+00:00',
            'step' => 1,
            'duration_ms' => 50.0,
            'failure_class' => 'App\\Exceptions\\StepFailure',
            'model' => ['provider' => '=provider-a', 'requested' => 'requested-only'],
        ]),
        EnvelopeFactory::record([
            'type' => 'tool.start',
            'invocation_id' => 'agent-failed',
            'at' => '2026-10-01T12:01:02.100000+00:00',
            'step' => 1,
            'tool_invocation_id' => 'tool-child',
            'tool' => '=dangerous-tool',
        ]),
        EnvelopeFactory::record([
            'type' => 'tool.end',
            'invocation_id' => 'agent-failed',
            'at' => '2026-10-01T12:01:02.200000+00:00',
            'step' => 1,
            'tool_invocation_id' => 'tool-child',
            'tool' => '=dangerous-tool',
            'outcome' => 'completed',
        ]),
        EnvelopeFactory::record([
            'type' => 'run.failover',
            'invocation_id' => 'agent-failed',
            'at' => '2026-10-01T12:01:02.300000+00:00',
            'model' => ['provider' => '=provider-a', 'requested' => 'requested-only'],
            'failure_class' => 'App\\Exceptions\\ProviderFailure',
        ]),
        EnvelopeFactory::record([
            'type' => 'run.end',
            'invocation_id' => 'agent-failed',
            'at' => '2026-10-01T12:01:04.000000+00:00',
            'outcome' => 'failed',
            'failure_class' => 'App\\Exceptions\\AgentFailure',
            'usage' => ['input_tokens' => 10],
            'model' => ['provider' => '=provider-a', 'requested' => 'requested-only'],
        ]),
        EnvelopeFactory::record([
            'type' => 'run.start',
            'invocation_id' => 'child-failed',
            'at' => '2026-10-01T12:01:02.150000+00:00',
            'parent_invocation_id' => 'agent-failed',
            'parent_tool_invocation_id' => 'tool-child',
            'agent' => 'App\\Ai\\ChildAgent',
        ]),
        EnvelopeFactory::record([
            'type' => 'run.end',
            'invocation_id' => 'child-failed',
            'at' => '2026-10-01T12:01:02.180000+00:00',
            'parent_invocation_id' => 'agent-failed',
            'parent_tool_invocation_id' => 'tool-child',
            'outcome' => 'failed',
            'failure_class' => 'App\\Exceptions\\ChildFailure',
        ]),
        EnvelopeFactory::record([
            'type' => 'run.start',
            'invocation_id' => 'agent-omitted',
            'at' => '2026-10-01T12:02:00.000000+00:00',
            'agent' => '=FormulaAgent',
        ]),
        EnvelopeFactory::record([
            'type' => 'run.end',
            'invocation_id' => 'agent-omitted',
            'at' => '2026-10-01T12:02:01.000000+00:00',
            'outcome' => 'completed',
        ]),
        EnvelopeFactory::record([
            'operation' => 'embeddings',
            'invocation_id' => 'embedding-completed',
            'attempt' => null,
            'at' => '2026-10-01T12:03:00.000000+00:00',
            'subject' => 'subject-embedding',
            'model' => ['provider' => 'provider-b', 'requested' => 'embed-requested'],
        ]),
        EnvelopeFactory::record([
            'type' => 'run.end',
            'operation' => 'embeddings',
            'invocation_id' => 'embedding-completed',
            'attempt' => null,
            'at' => '2026-10-01T12:03:00.040000+00:00',
            'outcome' => 'completed',
            'duration_ms' => 40.0,
            'usage' => ['input_tokens' => 4, 'output_tokens' => 0],
            'model' => ['provider' => 'provider-b', 'requested' => 'embed-requested', 'responded' => 'embed-responded'],
        ]),
        EnvelopeFactory::record([
            'type' => 'run.start',
            'operation' => 'audio',
            'invocation_id' => 'audio-lost',
            'attempt' => null,
            'at' => '2026-10-01T12:04:00.000000+00:00',
            'model' => ['provider' => 'provider-c', 'requested' => 'audio-model'],
        ]),
        EnvelopeFactory::record([
            'type' => 'run.start',
            'invocation_id' => 'agent-incomplete',
            'at' => '2026-10-01T12:05:00.000000+00:00',
            'agent' => 'App\\Ai\\IncompleteAgent',
        ]),
    ];
    $formulaEnvelope = EnvelopeFactory::envelope($formulaRecords, transportDrops: 9, hookDrops: 4);

    ProcessUsageEnvelope::fromContract('=formula-app', '2026-10-01T12:10:00.000000+00:00', $formulaEnvelope)
        ->handle(resolve(UsageIngestProcessor::class));

    $safeEnvelope = EnvelopeFactory::envelope([
        EnvelopeFactory::record([
            'operation' => 'classification',
            'invocation_id' => 'safe-operation',
            'attempt' => null,
            'at' => '2026-10-02T08:00:00.000000+00:00',
            'subject' => 'safe-subject',
            'model' => ['provider' => 'safe-provider', 'requested' => 'safe-requested'],
        ]),
        EnvelopeFactory::record([
            'type' => 'run.end',
            'operation' => 'classification',
            'invocation_id' => 'safe-operation',
            'attempt' => null,
            'at' => '2026-10-02T08:00:00.025000+00:00',
            'outcome' => 'completed',
            'usage' => ['input_tokens' => 7],
            'model' => ['provider' => 'safe-provider', 'requested' => 'safe-requested'],
        ]),
    ], transportDrops: 2, hookDrops: 7, sentAt: '2026-10-02T08:00:00.000000+00:00');

    ProcessUsageEnvelope::fromContract('safe-app', '2026-10-02T08:00:05.000000+00:00', $safeEnvelope)
        ->handle(resolve(UsageIngestProcessor::class));
    resolve(UsageIngestProcessor::class)->reconcileStale(CarbonImmutable::parse('2026-10-01T13:00:00.000000+00:00'));
}

it('projects discriminating PostgreSQL data across all seven dashboard tables', function (): void {
    $owner = dashboardOwner();
    seedDashboardData();

    $time = $this->actingAs($owner)->getJson(route('assay.dashboard.usage-over-time'))
        ->assertOk()->json('rows');
    expect($time)->toContainEqual([
        'day' => '2026-10-01',
        'app' => '=formula-app',
        'environment' => 'testing',
        'operation' => 'agent',
        'provider' => '=provider-a',
        'model' => 'requested-only',
        'metric' => 'input_tokens',
        'usage' => '10',
    ])->and($time)->toContainEqual([
        'day' => '2026-10-01',
        'app' => '=formula-app',
        'environment' => 'testing',
        'operation' => 'agent',
        'provider' => '=provider-a',
        'model' => 'responded-a',
        'metric' => 'input_tokens',
        'usage' => '0',
    ]);

    $agents = $this->actingAs($owner)->getJson(route('assay.dashboard.usage-by-agent'))
        ->assertOk()->json('rows');
    $formulaAgent = collect($agents)->firstWhere('agent', '=FormulaAgent');
    expect($formulaAgent)->toMatchArray([
        'run_count' => '3',
        'reported_run_count' => '2',
        'median_usage_per_run' => '5',
        'p95_usage_per_run' => '9.5',
        'median_steps_per_run' => '1',
    ]);

    $subjects = $this->actingAs($owner)->getJson(route('assay.dashboard.usage-by-subject'))
        ->assertOk()->json('rows');
    expect($subjects)->toContainEqual([
        'metric' => 'input_tokens',
        'app' => 'safe-app',
        'subject' => 'safe-subject',
        'run_count' => '1',
        'usage' => '7',
    ])->and(collect($subjects)->pluck('subject'))->not->toContain('not_reported');

    $top = $this->actingAs($owner)->getJson(route('assay.dashboard.top-runs'))
        ->assertOk()->json('rows');
    expect($top[0]['invocation_id'])->toBe('agent-failed')
        ->and($top[0]['usage'])->toBe('10')
        ->and($top[0])->toHaveKey('run_tree_id')
        ->and(collect($top)->pluck('invocation_id'))->not->toContain('agent-omitted');

    $reliability = $this->actingAs($owner)->getJson(route('assay.dashboard.reliability'))
        ->assertOk()->json('rows');
    expect($reliability)->toContainEqual([
        'measure' => 'agent_failure_rate',
        'dimension' => '=FormulaAgent',
        'event_count' => '1',
        'observation_count' => '3',
        'rate' => '0.33333333333333333333',
        'qualification' => 'observed_terminal_runs',
    ])->and($reliability)->toContainEqual([
        'measure' => 'non_agent_failed_or_lost_rate',
        'dimension' => 'audio',
        'event_count' => '1',
        'observation_count' => '1',
        'rate' => '1.00000000000000000000',
        'qualification' => 'upper_bound',
    ])->and($reliability)->toContainEqual([
        'measure' => 'tool_failure_rate',
        'dimension' => '=dangerous-tool',
        'event_count' => '1',
        'observation_count' => '1',
        'rate' => '1.00000000000000000000',
        'qualification' => 'includes_child_run_failures',
    ]);

    $latency = $this->actingAs($owner)->getJson(route('assay.dashboard.latency'))
        ->assertOk()->json('rows');
    expect($latency)->toContainEqual([
        'latency_scope' => 'step',
        'operation' => 'agent',
        'provider' => '=provider-a',
        'model' => 'requested-only',
        'sample_count' => '2',
        'p50_ms' => '40',
        'p95_ms' => '49',
    ])->and($latency)->toContainEqual([
        'latency_scope' => 'operation',
        'operation' => 'embeddings',
        'provider' => 'provider-b',
        'model' => 'embed-responded',
        'sample_count' => '1',
        'p50_ms' => '40',
        'p95_ms' => '40',
    ]);

    $pipeline = $this->actingAs($owner)->getJson(route('assay.dashboard.pipeline-health'))
        ->assertOk()->json('rows');
    expect($pipeline)->toContainEqual([
        'app' => '=formula-app',
        'measure' => 'transport_drop_max',
        'scope' => 'app_wide',
        'value' => '9',
        'status' => 'reported',
    ])->and($pipeline)->toContainEqual([
        'app' => '=formula-app',
        'measure' => 'hook_drop_max',
        'scope' => 'app_wide',
        'value' => '4',
        'status' => 'reported',
    ])->and($pipeline)->toContainEqual([
        'app' => '=formula-app',
        'measure' => 'content_incomplete_runs',
        'scope' => 'app',
        'value' => 'not_applicable',
        'status' => 'not_applicable',
    ])->and(collect($pipeline)->where('app', '=formula-app')->firstWhere('measure', 'incomplete_agent_runs')['value'])->toBe('1');

    $clockSkew = collect($pipeline)->where('app', '=formula-app')->firstWhere('measure', 'client_clock_skew');
    expect($clockSkew)->toMatchArray([
        'denominator' => '1',
        'p50_ms' => '600000',
        'p95_ms' => '600000',
        'status' => 'reported',
    ]);
});

it('keeps selected units separate and preserves omitted zero fractional and percentile semantics in HTML JSON and CSV', function (): void {
    $owner = dashboardOwner();
    seedDashboardData();

    $input = $this->actingAs($owner)->getJson(route('assay.dashboard.top-runs', ['metric' => 'input_tokens']))
        ->assertOk()->json('rows');
    $search = $this->actingAs($owner)->getJson(route('assay.dashboard.top-runs', ['metric' => 'search_units']))
        ->assertOk()->json('rows');

    expect(collect($input)->pluck('metric')->unique()->all())->toBe(['input_tokens'])
        ->and(collect($search)->pluck('metric')->unique()->all())->toBe(['search_units'])
        ->and($search[0]['invocation_id'])->toBe('fractional-search')
        ->and($search[0]['usage'])->toBe('0.125')
        ->and(collect($input)->firstWhere('invocation_id', '=agent-zero')['usage'])->toBe('0')
        ->and(collect($input)->pluck('invocation_id'))->not->toContain('agent-omitted');

    $this->actingAs($owner)->get(route('assay.dashboard', ['metric' => 'search_units']))
        ->assertOk()
        ->assertSee('0.125');
    $csv = $this->actingAs($owner)->get(route('assay.dashboard.top-runs.csv', ['metric' => 'search_units']))
        ->assertOk();
    expect(csvRows($csv)[0]['usage'])->toBe('0.125');
});

it('applies supported filters identically to JSON and CSV and labels app-wide pipeline maxima', function (): void {
    $owner = dashboardOwner();
    seedDashboardData();
    $filters = [
        'app' => 'safe-app',
        'environment' => 'testing',
        'operation' => 'classification',
        'metric' => 'input_tokens',
    ];

    $json = $this->actingAs($owner)->getJson(route('assay.dashboard.usage-over-time', $filters))
        ->assertOk()->json();
    $csv = $this->actingAs($owner)->get(route('assay.dashboard.usage-over-time.csv', $filters))
        ->assertOk();

    expect(csvRows($csv))->toBe($json['rows'])
        ->and($json['rows'])->toHaveCount(1)
        ->and($json['rows'][0])->toMatchArray([
            'app' => 'safe-app',
            'environment' => 'testing',
            'operation' => 'classification',
            'model' => 'safe-requested',
        ]);

    $pipelineJson = $this->actingAs($owner)->getJson(route('assay.dashboard.pipeline-health', ['app' => 'safe-app']))
        ->assertOk()->json();
    $pipelineCsv = $this->actingAs($owner)->get(route('assay.dashboard.pipeline-health.csv', ['app' => 'safe-app']))
        ->assertOk();
    $pipelineRows = array_map(
        static fn (array $row): array => array_map(
            static fn (string $header): string => $row[$header] ?? '',
            array_combine($pipelineJson['headers'], $pipelineJson['headers']),
        ),
        $pipelineJson['rows'],
    );

    expect(csvRows($pipelineCsv))->toBe($pipelineRows)
        ->and(collect($pipelineJson['rows'])->pluck('app')->unique()->all())->toBe(['safe-app'])
        ->and(collect($pipelineJson['rows'])->whereIn('measure', ['transport_drop_max', 'hook_drop_max'])->pluck('scope')->unique()->all())->toBe(['app_wide']);
});

it('validates exact metrics bounded limits and supported operation filters', function (): void {
    $owner = dashboardOwner();

    $this->actingAs($owner)->getJson(route('assay.dashboard.usage-over-time', ['metric' => 'Input_Tokens']))
        ->assertUnprocessable()->assertJsonValidationErrors('metric');
    $this->actingAs($owner)->getJson(route('assay.dashboard.usage-by-subject', ['limit' => 0]))
        ->assertUnprocessable()->assertJsonValidationErrors('limit');
    $this->actingAs($owner)->getJson(route('assay.dashboard.top-runs', ['limit' => 101]))
        ->assertUnprocessable()->assertJsonValidationErrors('limit');
    $this->actingAs($owner)->getJson(route('assay.dashboard.usage-over-time', ['operation' => 'unknown']))
        ->assertUnprocessable()->assertJsonValidationErrors('operation');
});

it('keeps all seven CSVs deterministic formula-neutral and free of forbidden fields and money vocabulary', function (): void {
    $owner = dashboardOwner();
    seedDashboardData();
    $tables = [
        'usage-over-time',
        'usage-by-agent',
        'usage-by-subject',
        'top-runs',
        'reliability',
        'latency',
        'pipeline-health',
    ];

    foreach ($tables as $table) {
        $json = $this->actingAs($owner)->getJson(route('assay.dashboard.'.$table))->assertOk()->json();
        $first = $this->actingAs($owner)->get(route('assay.dashboard.'.$table.'.csv'))->assertOk();
        $second = $this->actingAs($owner)->get(route('assay.dashboard.'.$table.'.csv'))->assertOk();
        $content = (string) $first->getContent();

        expect($first->headers->get('content-type'))->toContain('text/csv')
            ->and($content)->toBe($second->getContent())
            ->and(str_getcsv(strtok($content, "\r\n"), escape: ''))->toBe($json['headers'])
            ->and(strtolower($content))->not->toMatch('/\b(price|cost|currency|budget)\b|[$€]/')
            ->and(strtolower($content))->not->toContain('prompt', 'response_body', 'tool_arguments', 'tool_results', 'exception_message', 'credential', 'secret');
    }

    $timeCsv = (string) $this->actingAs($owner)->get(route('assay.dashboard.usage-over-time.csv'))->getContent();
    $agentCsv = (string) $this->actingAs($owner)->get(route('assay.dashboard.usage-by-agent.csv'))->getContent();
    $subjectCsv = (string) $this->actingAs($owner)->get(route('assay.dashboard.usage-by-subject.csv'))->getContent();
    $reliabilityCsv = (string) $this->actingAs($owner)->get(route('assay.dashboard.reliability.csv'))->getContent();

    expect($timeCsv)->toContain("'=formula-app", "'=provider-a")
        ->and($agentCsv)->toContain("'=FormulaAgent")
        ->and($subjectCsv)->toContain("'+subject-zero")
        ->and($reliabilityCsv)->toContain("'=dangerous-tool");
});
