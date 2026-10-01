<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ContentStore;
use RuntimeException;

final class ContentStoreRegistry
{
    public function recordContent(): string
    {
        return 'assay_record_content';
    }

    public function messages(): string
    {
        return 'assay_messages';
    }

    public function pendingContentAttaches(): string
    {
        return 'assay_pending_content_attaches';
    }

    /** @return list<ContentStore> */
    public function stores(): array
    {
        $maximumResidueHours = $this->boundedResidueHours();

        return [
            new ContentStore($this->recordContent(), ['content'], 'run_record', 'run_record'),
            new ContentStore($this->messages(), ['body'], 'run_message', 'run_message'),
            new ContentStore($this->pendingContentAttaches(), ['content'], 'timestamp', 'pending_target'),
            new ContentStore($this->queueJobs(), ['payload'], 'queue_timestamp', 'bounded_residue', $maximumResidueHours),
            new ContentStore($this->failedJobs(), ['payload', 'exception'], 'failed_queue_timestamp', 'bounded_residue', $maximumResidueHours),
        ];
    }

    /** @return array{stores: list<string>, protection: string, maximum_hours: int} */
    public function boundedResidueReport(): array
    {
        return [
            'stores' => [$this->queueJobs(), $this->failedJobs()],
            'protection' => 'encrypted_barrier',
            'maximum_hours' => $this->boundedResidueHours(),
        ];
    }

    public function boundedResidueHours(): int
    {
        $configured = config('assay.queue.failed_retention_hours');
        $contentDays = config('assay.retention.run_content_days');

        if (! is_int($configured) || $configured < 1 || ! is_int($contentDays) || $contentDays < 1) {
            throw new RuntimeException('Queue residue and content retention must be positive integers.');
        }

        return min($configured, $contentDays * 24);
    }

    public function queueJobs(): string
    {
        return (string) config('queue.connections.database.table', 'jobs');
    }

    public function failedJobs(): string
    {
        return (string) config('queue.failed.table', 'failed_jobs');
    }

    /** @return list<string> */
    public function tables(): array
    {
        return array_values(array_unique(array_map(
            static fn (ContentStore $store): string => $store->table,
            $this->stores(),
        )));
    }

    /** @return array<string, list<string>> */
    public function contentColumns(): array
    {
        $columns = [];

        foreach ($this->stores() as $store) {
            $columns[$store->table] = array_values(array_unique([
                ...($columns[$store->table] ?? []),
                ...$store->contentColumns,
            ]));
        }

        return $columns;
    }

    /**
     * Text and JSON columns in app-owned tables that are intentionally metadata or value-free infrastructure.
     * A schema-coverage test requires every app-owned text/JSON column to be classified here or as content.
     *
     * @return list<string>
     */
    public function nonContentColumns(): array
    {
        return [
            'assay_apps.app_ref',
            'assay_attempts.agent', 'assay_attempts.provider', 'assay_attempts.requested_model', 'assay_attempts.responded_model',
            'assay_content_access_overrides.access', 'assay_content_access_overrides.actor_id', 'assay_content_access_overrides.reason', 'assay_content_access_overrides.set_by_actor_id',
            'assay_content_attach_receipts.reason', 'assay_content_attach_receipts.status',
            'assay_envelope_sources.driver', 'assay_envelope_sources.package', 'assay_envelope_sources.version',
            'assay_envelopes.client_package', 'assay_envelopes.client_version', 'assay_envelopes.deploy', 'assay_envelopes.environment',
            'assay_erasure_records.key_version', 'assay_erasure_records.tombstone',
            'assay_pending_content_attaches.invocation_id',
            'assay_records.invocation_id', 'assay_records.operation', 'assay_records.outcome', 'assay_records.source', 'assay_records.type',
            'assay_run_failovers.failure_class', 'assay_run_failovers.provider', 'assay_run_failovers.requested_model',
            'assay_runs.agent', 'assay_runs.capture', 'assay_runs.client_package', 'assay_runs.client_version', 'assay_runs.deploy', 'assay_runs.environment',
            'assay_runs.failure_capture', 'assay_runs.failure_class', 'assay_runs.finish_reason', 'assay_runs.invocation_id', 'assay_runs.operation',
            'assay_runs.outcome', 'assay_runs.parent_tool_invocation_id', 'assay_runs.provider', 'assay_runs.replay_inputs_omitted',
            'assay_runs.requested_model', 'assay_runs.responded_model', 'assay_runs.source', 'assay_runs.status', 'assay_runs.subject',
            'assay_steps.agent', 'assay_steps.event', 'assay_steps.failure_class', 'assay_steps.finish_reason', 'assay_steps.provider',
            'assay_steps.requested_model', 'assay_steps.responded_model',
            'assay_tool_events.approval', 'assay_tool_events.event', 'assay_tool_events.failure_class', 'assay_tool_events.outcome',
            'assay_tool_events.tool', 'assay_tool_events.tool_invocation_id',
            'assay_usage_metrics.metric', 'assay_usage_metrics.provider', 'assay_usage_metrics.requested_model', 'assay_usage_metrics.responded_model', 'assay_usage_metrics.source',
            'cache.key', 'cache.value', 'cache_locks.key', 'cache_locks.owner',
            'failed_jobs.connection', 'failed_jobs.queue', 'failed_jobs.uuid',
            'job_batches.failed_job_ids', 'job_batches.id', 'job_batches.name', 'job_batches.options',
            'jobs.queue',
        ];
    }
}
