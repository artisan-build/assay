<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\DashboardTable;
use App\Enums\UsageMetric;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

final class UsageDashboard
{
    private const int MAXIMUM_LIMIT = 100;

    public function usageOverTime(
        UsageMetric $metric,
        ?string $app = null,
        ?string $environment = null,
        ?string $operation = null,
    ): DashboardTable {
        $conditions = [
            'um.metric = ?',
            "um.source IN ('agent_step', 'non_agent')",
        ];
        $bindings = [$metric->value];

        foreach ([
            'a.app_ref' => $app,
            'r.environment' => $environment,
            'r.operation' => $operation,
        ] as $column => $value) {
            if ($value !== null) {
                $conditions[] = "{$column} = ?";
                $bindings[] = $value;
            }
        }

        $where = implode(' AND ', $conditions);
        $rows = $this->rows(<<<SQL
            SELECT
                to_char(date_trunc('day', rec.occurred_at AT TIME ZONE 'UTC'), 'YYYY-MM-DD') AS day,
                a.app_ref AS app,
                COALESCE(r.environment, 'not_reported') AS environment,
                COALESCE(r.operation, 'not_reported') AS operation,
                COALESCE(um.provider, r.provider, 'not_reported') AS provider,
                COALESCE(
                    um.responded_model,
                    um.requested_model,
                    r.responded_model,
                    r.requested_model,
                    'not_reported'
                ) AS model,
                um.metric,
                SUM(um.value)::text AS usage
            FROM assay_usage_metrics um
            INNER JOIN assay_records rec ON rec.id = um.record_id
            INNER JOIN assay_runs r ON r.id = um.run_id
            INNER JOIN assay_apps a ON a.id = r.app_id
            WHERE {$where}
            GROUP BY
                date_trunc('day', rec.occurred_at AT TIME ZONE 'UTC'),
                a.app_ref,
                r.environment,
                r.operation,
                COALESCE(um.provider, r.provider, 'not_reported'),
                COALESCE(
                    um.responded_model,
                    um.requested_model,
                    r.responded_model,
                    r.requested_model,
                    'not_reported'
                ),
                um.metric
            ORDER BY day, app, environment, operation, provider, model
            SQL, $bindings);

        return new DashboardTable(
            ['day', 'app', 'environment', 'operation', 'provider', 'model', 'metric', 'usage'],
            $rows,
            ['app', 'environment', 'operation', 'provider', 'model'],
        );
    }

    public function usageByAgent(UsageMetric $metric): DashboardTable
    {
        $rows = $this->rows(<<<'SQL'
            WITH RECURSIVE direct_usage AS (
                SELECT run_id, SUM(value) AS usage
                FROM assay_usage_metrics
                WHERE metric = ? AND source IN ('agent_step', 'non_agent')
                GROUP BY run_id
            ), run_descendants AS (
                SELECT id AS run_id, id AS descendant_run_id
                FROM assay_runs

                UNION ALL

                SELECT run_descendants.run_id, child.id
                FROM run_descendants
                INNER JOIN assay_runs child
                    ON child.parent_run_id = run_descendants.descendant_run_id
            ), subtree_usage AS (
                SELECT run_descendants.run_id, SUM(direct_usage.usage) AS usage
                FROM run_descendants
                INNER JOIN direct_usage
                    ON direct_usage.run_id = run_descendants.descendant_run_id
                GROUP BY run_descendants.run_id
            ), run_stats AS (
                SELECT
                    r.id,
                    COALESCE(r.agent, 'not_reported') AS agent,
                    subtree_usage.usage,
                    COUNT(s.id) FILTER (WHERE s.event IN ('end', 'fail')) AS step_count
                FROM assay_runs r
                LEFT JOIN subtree_usage ON subtree_usage.run_id = r.id
                LEFT JOIN assay_steps s ON s.run_id = r.id
                WHERE r.operation = 'agent'
                GROUP BY r.id, r.agent, subtree_usage.usage
            )
            SELECT
                CAST(? AS text) AS metric,
                agent,
                COUNT(*)::text AS run_count,
                COUNT(usage)::text AS reported_run_count,
                COALESCE(
                    (percentile_cont(0.5) WITHIN GROUP (ORDER BY usage))::text,
                    'not_reported'
                ) AS median_usage_per_run,
                COALESCE(
                    (percentile_cont(0.95) WITHIN GROUP (ORDER BY usage))::text,
                    'not_reported'
                ) AS p95_usage_per_run,
                (percentile_cont(0.5) WITHIN GROUP (ORDER BY step_count))::text AS median_steps_per_run
            FROM run_stats
            GROUP BY agent
            ORDER BY agent
            SQL, [$metric->value, $metric->value]);

        return new DashboardTable(
            [
                'metric',
                'agent',
                'run_count',
                'reported_run_count',
                'median_usage_per_run',
                'p95_usage_per_run',
                'median_steps_per_run',
            ],
            $rows,
            ['agent'],
        );
    }

    public function usageBySubject(UsageMetric $metric, int $limit = 25): DashboardTable
    {
        $limit = $this->limit($limit);
        $rows = $this->rows(<<<SQL
            SELECT
                um.metric,
                a.app_ref AS app,
                r.subject,
                COUNT(DISTINCT r.id)::text AS run_count,
                SUM(um.value)::text AS usage
            FROM assay_usage_metrics um
            INNER JOIN assay_runs r ON r.id = um.run_id
            INNER JOIN assay_apps a ON a.id = r.app_id
            WHERE
                um.metric = ?
                AND um.source IN ('agent_step', 'non_agent')
                AND r.subject IS NOT NULL
            GROUP BY um.metric, a.app_ref, r.subject
            ORDER BY SUM(um.value) DESC, a.app_ref, r.subject
            LIMIT {$limit}
            SQL, [$metric->value]);

        return new DashboardTable(
            ['metric', 'app', 'subject', 'run_count', 'usage'],
            $rows,
            ['app', 'subject'],
        );
    }

    public function topRuns(
        UsageMetric $metric,
        bool $canViewContent,
        int $limit = 25,
    ): DashboardTable {
        $limit = $this->limit($limit);
        $rows = $this->rows(<<<SQL
            WITH RECURSIVE direct_usage AS (
                SELECT run_id, metric, SUM(value) AS usage
                FROM assay_usage_metrics
                WHERE metric = ? AND source IN ('agent_step', 'non_agent')
                GROUP BY run_id, metric
            ), run_descendants AS (
                SELECT id AS run_id, id AS descendant_run_id
                FROM assay_runs

                UNION ALL

                SELECT run_descendants.run_id, child.id
                FROM run_descendants
                INNER JOIN assay_runs child
                    ON child.parent_run_id = run_descendants.descendant_run_id
            ), subtree_usage AS (
                SELECT
                    run_descendants.run_id,
                    direct_usage.metric,
                    SUM(direct_usage.usage) AS usage
                FROM run_descendants
                INNER JOIN direct_usage
                    ON direct_usage.run_id = run_descendants.descendant_run_id
                GROUP BY run_descendants.run_id, direct_usage.metric
            )
            SELECT
                subtree_usage.metric,
                r.id AS run_id,
                a.app_ref AS app,
                r.invocation_id,
                COALESCE(r.environment, 'not_reported') AS environment,
                COALESCE(r.operation, 'not_reported') AS operation,
                COALESCE(r.agent, 'not_reported') AS agent,
                COALESCE(r.subject, 'not_reported') AS subject,
                r.status,
                subtree_usage.usage::text AS usage
            FROM subtree_usage
            INNER JOIN assay_runs r ON r.id = subtree_usage.run_id
            INNER JOIN assay_apps a ON a.id = r.app_id
            ORDER BY subtree_usage.usage DESC, r.id
            LIMIT {$limit}
            SQL, [$metric->value]);

        $headers = [
            'metric',
            'run_id',
            'app',
            'invocation_id',
            'environment',
            'operation',
            'agent',
            'subject',
            'status',
            'usage',
        ];

        if ($canViewContent) {
            $headers[] = 'run_tree_id';

            foreach ($rows as &$row) {
                $row['run_tree_id'] = $row['run_id'];
            }
            unset($row);
        }

        return new DashboardTable(
            $headers,
            $rows,
            ['app', 'invocation_id', 'environment', 'operation', 'agent', 'subject', 'status'],
        );
    }

    public function reliability(): DashboardTable
    {
        $rows = $this->rows(<<<'SQL'
            WITH agent_rates AS (
                SELECT
                    COALESCE(agent, 'not_reported') AS dimension,
                    COUNT(*) FILTER (WHERE status = 'failed') AS event_count,
                    COUNT(*) AS observation_count
                FROM assay_runs
                WHERE operation = 'agent' AND status IN ('completed', 'failed')
                GROUP BY COALESCE(agent, 'not_reported')
            ), non_agent_rates AS (
                SELECT
                    operation AS dimension,
                    COUNT(*) FILTER (WHERE status = 'failed_or_lost') AS event_count,
                    COUNT(*) AS observation_count
                FROM assay_runs
                WHERE operation <> 'agent' AND status IN ('completed', 'failed', 'failed_or_lost')
                GROUP BY operation
            ), failed_step_counts AS (
                SELECT
                    COALESCE(s.provider, r.provider, 'not_reported') AS provider,
                    COALESCE(
                        s.responded_model,
                        s.requested_model,
                        r.responded_model,
                        r.requested_model,
                        'not_reported'
                    ) AS model,
                    COUNT(*) AS event_count
                FROM assay_steps s
                INNER JOIN assay_runs r ON r.id = s.run_id
                WHERE s.event = 'fail'
                GROUP BY
                    COALESCE(s.provider, r.provider, 'not_reported'),
                    COALESCE(
                        s.responded_model,
                        s.requested_model,
                        r.responded_model,
                        r.requested_model,
                        'not_reported'
                    )
            ), model_observations AS (
                SELECT
                    a.id::text AS observation_id,
                    COALESCE(a.provider, r.provider, 'not_reported') AS provider,
                    COALESCE(
                        a.responded_model,
                        a.requested_model,
                        r.responded_model,
                        r.requested_model,
                        'not_reported'
                    ) AS model
                FROM assay_attempts a
                INNER JOIN assay_runs r ON r.id = a.run_id

                UNION ALL

                SELECT
                    r.id::text AS observation_id,
                    COALESCE(r.provider, 'not_reported') AS provider,
                    COALESCE(r.responded_model, r.requested_model, 'not_reported') AS model
                FROM assay_runs r
                WHERE r.operation <> 'agent'
            ), model_totals AS (
                SELECT provider, model, COUNT(*) AS observation_count
                FROM model_observations
                GROUP BY provider, model
            ), failover_totals AS (
                SELECT provider, requested_model AS model, COUNT(*) AS event_count
                FROM assay_run_failovers
                GROUP BY provider, requested_model
            ), failover_rates AS (
                SELECT
                    COALESCE(f.provider, m.provider) AS provider,
                    COALESCE(f.model, m.model) AS model,
                    COALESCE(f.event_count, 0) AS event_count,
                    COALESCE(m.observation_count, 0) AS observation_count
                FROM failover_totals f
                FULL OUTER JOIN model_totals m USING (provider, model)
            ), child_failures AS (
                SELECT
                    parent_run_id,
                    parent_tool_invocation_id,
                    BOOL_OR(status = 'failed') AS failed
                FROM assay_runs
                WHERE parent_run_id IS NOT NULL AND parent_tool_invocation_id IS NOT NULL
                GROUP BY parent_run_id, parent_tool_invocation_id
            ), tool_invocations AS (
                SELECT
                    te.run_id,
                    te.tool_invocation_id,
                    COALESCE(MAX(te.tool), 'not_reported') AS tool,
                    BOOL_OR(te.event = 'end') OR COALESCE(BOOL_OR(cf.failed), false) AS observed,
                    BOOL_OR(te.event = 'end' AND te.outcome = 'failed')
                        OR COALESCE(BOOL_OR(cf.failed), false) AS failed
                FROM assay_tool_events te
                LEFT JOIN child_failures cf
                    ON cf.parent_run_id = te.run_id
                    AND cf.parent_tool_invocation_id = te.tool_invocation_id
                GROUP BY te.run_id, te.tool_invocation_id
            ), tool_rates AS (
                SELECT
                    tool AS dimension,
                    COUNT(*) FILTER (WHERE failed) AS event_count,
                    COUNT(*) AS observation_count
                FROM tool_invocations
                WHERE observed
                GROUP BY tool
            ), reliability_rows AS (
                SELECT
                    1 AS sort_order,
                    'agent_failure_rate'::text AS measure,
                    dimension,
                    NULL::text AS provider,
                    NULL::text AS model,
                    event_count,
                    observation_count,
                    (event_count::numeric / observation_count)::text AS rate,
                    'observed_terminal_runs'::text AS qualification
                FROM agent_rates

                UNION ALL

                SELECT
                    2,
                    'non_agent_failed_or_lost_rate',
                    dimension,
                    NULL::text,
                    NULL::text,
                    event_count,
                    observation_count,
                    (event_count::numeric / observation_count)::text,
                    'upper_bound'
                FROM non_agent_rates

                UNION ALL

                SELECT
                    3,
                    'failed_step_count',
                    NULL::text,
                    provider,
                    model,
                    event_count,
                    NULL::bigint,
                    NULL::text,
                    'usage_unavailable_count_only'
                FROM failed_step_counts

                UNION ALL

                SELECT
                    4,
                    'failover_rate',
                    NULL::text,
                    provider,
                    model,
                    event_count,
                    observation_count,
                    CASE
                        WHEN observation_count = 0 THEN 'not_reported'
                        ELSE (event_count::numeric / observation_count)::text
                    END,
                    'per_model_observation'
                FROM failover_rates

                UNION ALL

                SELECT
                    5,
                    'tool_failure_rate',
                    dimension,
                    NULL::text,
                    NULL::text,
                    event_count,
                    observation_count,
                    (event_count::numeric / observation_count)::text,
                    'includes_child_run_failures'
                FROM tool_rates
            )
            SELECT
                measure,
                dimension,
                provider,
                model,
                event_count::text AS event_count,
                observation_count::text AS observation_count,
                rate,
                qualification
            FROM reliability_rows
            ORDER BY sort_order, dimension NULLS FIRST, provider NULLS FIRST, model NULLS FIRST
            SQL);

        return new DashboardTable(
            [
                'measure',
                'dimension',
                'provider',
                'model',
                'event_count',
                'observation_count',
                'rate',
                'qualification',
            ],
            $rows,
            ['dimension', 'provider', 'model'],
        );
    }

    public function latency(): DashboardTable
    {
        $rows = $this->rows(<<<'SQL'
            WITH latency_rows AS (
                SELECT
                    1 AS sort_order,
                    'step'::text AS latency_scope,
                    'agent'::text AS operation,
                    COALESCE(s.provider, r.provider, 'not_reported') AS provider,
                    COALESCE(
                        s.responded_model,
                        s.requested_model,
                        r.responded_model,
                        r.requested_model,
                        'not_reported'
                    ) AS model,
                    s.duration_ms
                FROM assay_steps s
                INNER JOIN assay_runs r ON r.id = s.run_id
                WHERE s.event IN ('end', 'fail') AND s.duration_ms IS NOT NULL

                UNION ALL

                SELECT
                    2,
                    'operation',
                    r.operation,
                    COALESCE(r.provider, 'not_reported'),
                    COALESCE(r.responded_model, r.requested_model, 'not_reported'),
                    r.duration_ms
                FROM assay_runs r
                WHERE r.operation <> 'agent' AND r.duration_ms IS NOT NULL
            )
            SELECT
                latency_scope,
                operation,
                provider,
                model,
                COUNT(*)::text AS sample_count,
                (percentile_cont(0.5) WITHIN GROUP (ORDER BY duration_ms))::text AS p50_ms,
                (percentile_cont(0.95) WITHIN GROUP (ORDER BY duration_ms))::text AS p95_ms
            FROM latency_rows
            GROUP BY sort_order, latency_scope, operation, provider, model
            ORDER BY sort_order, operation, provider, model
            SQL);

        return new DashboardTable(
            ['latency_scope', 'operation', 'provider', 'model', 'sample_count', 'p50_ms', 'p95_ms'],
            $rows,
            ['operation', 'provider', 'model'],
        );
    }

    public function pipelineHealth(?string $app = null): DashboardTable
    {
        $bindings = [];
        $where = '';

        if ($app !== null) {
            $where = 'WHERE a.app_ref = ?';
            $bindings[] = $app;
        }

        $rows = $this->rows(<<<SQL
            WITH app_scope AS (
                SELECT a.*
                FROM assay_apps a
                {$where}
            ), run_health AS (
                SELECT
                    a.id AS app_id,
                    COUNT(r.id) FILTER (
                        WHERE r.status = 'incomplete'
                            AND (r.operation = 'agent' OR r.operation IS NULL)
                    ) AS incomplete_agent_count,
                    COUNT(r.id) FILTER (WHERE r.subject IS NULL) AS no_subject_count,
                    COUNT(r.id) AS run_count
                FROM app_scope a
                LEFT JOIN assay_runs r ON r.app_id = a.id
                GROUP BY a.id
            ), clock_health AS (
                SELECT
                    env.app_id,
                    COUNT(*) AS sample_count,
                    percentile_cont(0.5) WITHIN GROUP (
                        ORDER BY ABS(EXTRACT(EPOCH FROM (env.received_at - env.sent_at)) * 1000)
                    ) AS p50_ms,
                    percentile_cont(0.95) WITHIN GROUP (
                        ORDER BY ABS(EXTRACT(EPOCH FROM (env.received_at - env.sent_at)) * 1000)
                    ) AS p95_ms
                FROM assay_envelopes env
                INNER JOIN app_scope a ON a.id = env.app_id
                GROUP BY env.app_id
            ), health_rows AS (
                SELECT
                    1 AS sort_order,
                    a.app_ref AS app,
                    'transport_drop_max'::text AS measure,
                    'app_wide'::text AS scope,
                    a.dropped_transport_total::text AS value,
                    NULL::text AS numerator,
                    NULL::text AS denominator,
                    NULL::text AS rate,
                    NULL::text AS p50_ms,
                    NULL::text AS p95_ms,
                    'reported'::text AS status
                FROM app_scope a

                UNION ALL

                SELECT
                    2,
                    a.app_ref,
                    'hook_drop_max',
                    'app_wide',
                    a.dropped_hook_total::text,
                    NULL::text,
                    NULL::text,
                    NULL::text,
                    NULL::text,
                    NULL::text,
                    'reported'
                FROM app_scope a

                UNION ALL

                SELECT
                    3,
                    a.app_ref,
                    'incomplete_agent_runs',
                    'app',
                    rh.incomplete_agent_count::text,
                    NULL::text,
                    NULL::text,
                    NULL::text,
                    NULL::text,
                    NULL::text,
                    'reported'
                FROM app_scope a
                INNER JOIN run_health rh ON rh.app_id = a.id

                UNION ALL

                SELECT
                    4,
                    a.app_ref,
                    'content_incomplete_runs',
                    'app',
                    'not_applicable',
                    NULL::text,
                    NULL::text,
                    NULL::text,
                    NULL::text,
                    NULL::text,
                    'not_applicable'
                FROM app_scope a

                UNION ALL

                SELECT
                    5,
                    a.app_ref,
                    'no_subject_share',
                    'app',
                    NULL::text,
                    rh.no_subject_count::text,
                    rh.run_count::text,
                    CASE
                        WHEN rh.run_count = 0 THEN 'not_reported'
                        ELSE (rh.no_subject_count::numeric / rh.run_count)::text
                    END,
                    NULL::text,
                    NULL::text,
                    CASE WHEN rh.run_count = 0 THEN 'not_reported' ELSE 'reported' END
                FROM app_scope a
                INNER JOIN run_health rh ON rh.app_id = a.id

                UNION ALL

                SELECT
                    6,
                    a.app_ref,
                    'client_clock_skew',
                    'app',
                    NULL::text,
                    NULL::text,
                    COALESCE(ch.sample_count, 0)::text,
                    NULL::text,
                    COALESCE(ch.p50_ms::text, 'not_reported'),
                    COALESCE(ch.p95_ms::text, 'not_reported'),
                    CASE WHEN ch.sample_count IS NULL THEN 'not_reported' ELSE 'reported' END
                FROM app_scope a
                LEFT JOIN clock_health ch ON ch.app_id = a.id
            )
            SELECT
                app,
                measure,
                scope,
                value,
                numerator,
                denominator,
                rate,
                p50_ms,
                p95_ms,
                status
            FROM health_rows
            ORDER BY app, sort_order
            SQL, $bindings);

        return new DashboardTable(
            [
                'app',
                'measure',
                'scope',
                'value',
                'numerator',
                'denominator',
                'rate',
                'p50_ms',
                'p95_ms',
                'status',
            ],
            $rows,
            ['app'],
        );
    }

    public function runTree(string $runId): DashboardTable
    {
        $rows = $this->rows(<<<'SQL'
            WITH RECURSIVE run_tree AS (
                SELECT * FROM assay_runs WHERE id = ?

                UNION ALL

                SELECT child.*
                FROM assay_runs child
                INNER JOIN run_tree parent ON child.parent_run_id = parent.id
            )
            SELECT
                id AS run_id,
                parent_run_id,
                parent_tool_invocation_id,
                invocation_id,
                COALESCE(operation, 'not_reported') AS operation,
                COALESCE(agent, 'not_reported') AS agent,
                COALESCE(provider, 'not_reported') AS provider,
                COALESCE(requested_model, 'not_reported') AS requested_model,
                COALESCE(responded_model, requested_model, 'not_reported') AS responded_model,
                COALESCE(subject, 'not_reported') AS subject,
                status,
                failure_class
            FROM run_tree
            ORDER BY started_at NULLS LAST, id
            SQL, [$runId]);

        return new DashboardTable(
            [
                'run_id',
                'parent_run_id',
                'parent_tool_invocation_id',
                'invocation_id',
                'operation',
                'agent',
                'provider',
                'requested_model',
                'responded_model',
                'subject',
                'status',
                'failure_class',
            ],
            $rows,
            [
                'parent_tool_invocation_id',
                'invocation_id',
                'operation',
                'agent',
                'provider',
                'requested_model',
                'responded_model',
                'subject',
                'status',
                'failure_class',
            ],
        );
    }

    private function limit(int $limit): int
    {
        return max(1, min(self::MAXIMUM_LIMIT, $limit));
    }

    /**
     * @param  list<string|int|float|bool|null>  $bindings
     * @return list<array<string, string>>
     */
    private function rows(string $query, array $bindings = []): array
    {
        return array_map(function (stdClass $row): array {
            $result = [];

            foreach (get_object_vars($row) as $column => $value) {
                if ($value === null) {
                    continue;
                }

                if (! is_scalar($value)) {
                    throw new RuntimeException("Dashboard column {$column} is not scalar.");
                }

                $result[$column] = match (true) {
                    is_bool($value) => $value ? 'true' : 'false',
                    default => (string) $value,
                };
            }

            return $result;
        }, DB::select($query, $bindings));
    }
}
