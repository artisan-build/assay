<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ContentPersistenceFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use stdClass;

final class UsageIngestProcessor
{
    /**
     * @param  array<string, mixed>  $envelope
     */
    public function process(string $appRef, string $receivedAt, array $envelope): void
    {
        DB::transaction(function () use ($appRef, $receivedAt, $envelope): void {
            $appId = $this->app($appRef, $envelope, $receivedAt);
            $envelopeId = (string) Str::uuid();

            $inserted = DB::table('assay_envelopes')->insertOrIgnore([
                'id' => $envelopeId,
                'app_id' => $appId,
                'envelope_id' => $envelope['envelope_id'],
                'sent_at' => $envelope['sent_at'],
                'received_at' => $receivedAt,
                'client_package' => $envelope['client']['package'],
                'client_version' => $envelope['client']['version'],
                'environment' => $envelope['environment'],
                'deploy' => $envelope['deploy'],
            ]);

            if ($inserted === 0) {
                return;
            }

            foreach ($envelope['sources'] as $source) {
                DB::table('assay_envelope_sources')->insert([
                    'envelope_id' => $envelopeId,
                    'driver' => $source['driver'],
                    'package' => $source['package'],
                    'version' => $source['version'],
                ]);
            }

            foreach ($envelope['records'] as $record) {
                $this->record($appId, $envelopeId, $receivedAt, $envelope, $record);
            }
        }, 3);
    }

    /** @param array<string, mixed> $envelope */
    private function app(string $appRef, array $envelope, string $receivedAt): string
    {
        /** @var stdClass $app */
        $app = DB::selectOne(<<<'SQL'
            INSERT INTO assay_apps (
                id, app_ref, dropped_transport_total, dropped_hook_total, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT (app_ref) DO UPDATE SET
                dropped_transport_total = GREATEST(assay_apps.dropped_transport_total, EXCLUDED.dropped_transport_total),
                dropped_hook_total = GREATEST(assay_apps.dropped_hook_total, EXCLUDED.dropped_hook_total),
                updated_at = EXCLUDED.updated_at
            RETURNING id
            SQL, [
            (string) Str::uuid(),
            $appRef,
            (int) $envelope['dropped_transport_total'],
            (int) $envelope['dropped_hook_total'],
            $receivedAt,
            $receivedAt,
        ]);

        return (string) $app->id;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $record
     */
    private function record(string $appId, string $envelopeId, string $receivedAt, array $envelope, array $record): void
    {
        $recordRowId = (string) Str::uuid();
        $inserted = DB::table('assay_records')->insertOrIgnore([
            'id' => $recordRowId,
            'app_id' => $appId,
            'envelope_id' => $envelopeId,
            'record_id' => $record['record_id'],
            'source' => $record['source'],
            'type' => $record['type'],
            'occurred_at' => $record['at'],
            'received_at' => $receivedAt,
        ]);

        if ($inserted === 0) {
            return;
        }

        if (! isset($record['invocation_id'])) {
            $this->unattributedFailover($appId, $recordRowId, $record);

            return;
        }

        $parentRunId = isset($record['parent_invocation_id'])
            ? $this->run($appId, (string) $record['parent_invocation_id'], $receivedAt)
            : null;
        $runId = $this->run($appId, (string) $record['invocation_id'], $receivedAt);
        $this->updateRun($runId, $parentRunId, $receivedAt, $envelope, $record);
        $attemptId = isset($record['attempt'])
            ? $this->attempt($runId, (int) $record['attempt'], $record)
            : null;

        match ($record['type']) {
            'step.start', 'step.end', 'step.fail' => $this->step($recordRowId, $runId, $attemptId, $record),
            'tool.start', 'tool.end', 'tool.approval' => $this->tool($recordRowId, $runId, $attemptId, $record),
            'run.failover' => $this->failover($appId, $recordRowId, $runId, $record),
            default => null,
        };

        if (isset($record['usage'])) {
            $this->usage($recordRowId, $runId, $attemptId, $record);
        }

        if (isset($record['content'])) {
            $this->content($recordRowId, $runId, (string) $record['record_id'], $record);
        }

        $this->reconcileRun($runId, CarbonImmutable::parse($receivedAt));
    }

    private function run(string $appId, string $invocationId, string $receivedAt): string
    {
        $candidate = (string) Str::uuid();

        DB::table('assay_runs')->insertOrIgnore([
            'id' => $candidate,
            'app_id' => $appId,
            'invocation_id' => $invocationId,
            'status' => 'pending',
            'checksum_mismatch' => false,
            'earliest_received_at' => $receivedAt,
        ]);

        /** @var string $runId */
        $runId = DB::table('assay_runs')
            ->where('app_id', $appId)
            ->where('invocation_id', $invocationId)
            ->value('id');

        DB::update(
            'UPDATE assay_runs SET earliest_received_at = LEAST(earliest_received_at, ?) WHERE id = ?',
            [$receivedAt, $runId],
        );

        return $runId;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $record
     */
    private function updateRun(string $runId, ?string $parentRunId, string $receivedAt, array $envelope, array $record): void
    {
        /** @var stdClass $existing */
        $existing = DB::table('assay_runs')->where('id', $runId)->sole();
        $values = $this->present($record, [
            'operation' => 'operation',
            'parent_tool_invocation_id' => 'parent_tool_invocation_id',
            'source' => 'source',
            'capture' => 'capture',
            'sampled' => 'sampled',
            'subject' => 'subject',
            'agent' => 'agent',
            'failure_capture' => 'failure_capture',
            'replay_inputs_omitted' => 'replay_inputs_omitted',
        ]);
        $values += [
            'environment' => $envelope['environment'],
            'deploy' => $envelope['deploy'],
            'client_package' => $envelope['client']['package'],
            'client_version' => $envelope['client']['version'],
        ];

        if (isset($values['replay_inputs_omitted'])) {
            $values['replay_inputs_omitted'] = json_encode($values['replay_inputs_omitted'], JSON_THROW_ON_ERROR);
        }

        if ($parentRunId !== null) {
            $values['parent_run_id'] = $parentRunId;
        }

        $terminalModelAlreadyKnown = $existing->ended_at !== null && $record['type'] !== 'run.end';

        if (isset($record['model']) && ! $terminalModelAlreadyKnown) {
            $values += $this->present($record['model'], [
                'provider' => 'provider',
                'requested' => 'requested_model',
                'responded' => 'responded_model',
            ]);
        }

        if ($record['type'] === 'run.start') {
            $values['started_at'] = $existing->started_at === null
                || CarbonImmutable::parse((string) $record['at'])->lessThan(CarbonImmutable::parse((string) $existing->started_at))
                    ? $record['at']
                    : $existing->started_at;
            $values['started_received_at'] = $existing->started_received_at === null
                || CarbonImmutable::parse($receivedAt)->lessThan(CarbonImmutable::parse((string) $existing->started_received_at))
                    ? $receivedAt
                    : $existing->started_received_at;
        }

        if ($record['type'] === 'run.end') {
            $values['ended_at'] = $record['at'];
            $values['ended_received_at'] = $receivedAt;
            $values['outcome'] = $record['outcome'];
            $values['failure_class'] = $record['failure_class'] ?? null;
            $values['finish_reason'] = $record['finish_reason'] ?? null;

            if (($record['operation'] ?? $existing->operation) !== 'agent' && array_key_exists('duration_ms', $record)) {
                $values['duration_ms'] = $this->decimal($record['duration_ms']);
            }

            if (isset($record['attempt'])) {
                $values['terminal_attempt'] = $record['attempt'];
                DB::table('assay_attempts')->where('run_id', $runId)->update(['terminal' => false]);
            }
        }

        DB::table('assay_runs')->where('id', $runId)->update($values);
    }

    /** @param array<string, mixed> $record */
    private function attempt(string $runId, int $ordinal, array $record): string
    {
        $candidate = (string) Str::uuid();

        DB::table('assay_attempts')->insertOrIgnore([
            'id' => $candidate,
            'run_id' => $runId,
            'ordinal' => $ordinal,
            'terminal' => false,
        ]);

        /** @var string $attemptId */
        $attemptId = DB::table('assay_attempts')
            ->where('run_id', $runId)
            ->where('ordinal', $ordinal)
            ->value('id');
        $values = $this->present($record, ['agent' => 'agent']);

        if (isset($record['model'])) {
            $values += $this->present($record['model'], [
                'provider' => 'provider',
                'requested' => 'requested_model',
                'responded' => 'responded_model',
            ]);
        }

        if ($record['type'] === 'run.start') {
            $values['started_at'] = $record['at'];
        }

        if ($record['type'] === 'run.end') {
            $values['ended_at'] = $record['at'];
            $values['terminal'] = true;
        }

        if ($values !== []) {
            DB::table('assay_attempts')->where('id', $attemptId)->update($values);
        }

        return $attemptId;
    }

    /** @param array<string, mixed> $record */
    private function step(string $recordId, string $runId, ?string $attemptId, array $record): void
    {
        DB::table('assay_steps')->insert([
            'id' => (string) Str::uuid(),
            'record_id' => $recordId,
            'run_id' => $runId,
            'attempt_id' => $attemptId,
            'event' => str_replace('step.', '', (string) $record['type']),
            'step_number' => $record['step'],
            'occurred_at' => $record['at'],
            'duration_ms' => $record['duration_ms'] ?? null,
            'agent' => $record['agent'] ?? null,
            'provider' => $record['model']['provider'] ?? null,
            'requested_model' => $record['model']['requested'] ?? null,
            'responded_model' => $record['model']['responded'] ?? null,
            'finish_reason' => $record['finish_reason'] ?? null,
            'failure_class' => $record['failure_class'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $record */
    private function tool(string $recordId, string $runId, ?string $attemptId, array $record): void
    {
        DB::table('assay_tool_events')->insert([
            'id' => (string) Str::uuid(),
            'record_id' => $recordId,
            'run_id' => $runId,
            'attempt_id' => $attemptId,
            'event' => str_replace('tool.', '', (string) $record['type']),
            'step_number' => $record['step'] ?? null,
            'tool_invocation_id' => $record['tool_invocation_id'],
            'tool' => $record['tool'] ?? null,
            'occurred_at' => $record['at'],
            'duration_ms' => $record['duration_ms'] ?? null,
            'outcome' => $record['outcome'] ?? null,
            'approval' => $record['approval'] ?? null,
            'failure_class' => $record['failure_class'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $record */
    private function failover(string $appId, string $recordId, ?string $runId, array $record): void
    {
        DB::table('assay_run_failovers')->insert([
            'id' => (string) Str::uuid(),
            'record_id' => $recordId,
            'app_id' => $appId,
            'run_id' => $runId,
            'attempt' => $record['attempt'] ?? null,
            'provider' => $record['model']['provider'],
            'requested_model' => $record['model']['requested'],
            'failure_class' => $record['failure_class'] ?? null,
            'occurred_at' => $record['at'],
        ]);
    }

    /** @param array<string, mixed> $record */
    private function unattributedFailover(string $appId, string $recordId, array $record): void
    {
        $this->failover($appId, $recordId, null, $record);
    }

    /** @param array<string, mixed> $record */
    private function usage(string $recordId, string $runId, ?string $attemptId, array $record): void
    {
        $source = $record['operation'] === 'agent'
            ? ($record['type'] === 'step.end' ? 'agent_step' : 'agent_checksum')
            : 'non_agent';

        foreach ($record['usage'] as $metric => $value) {
            DB::table('assay_usage_metrics')->insert([
                'record_id' => $recordId,
                'run_id' => $runId,
                'attempt_id' => $attemptId,
                'source' => $source,
                'metric' => $metric,
                'value' => $this->decimal($value),
                'provider' => $record['model']['provider'] ?? null,
                'requested_model' => $record['model']['requested'] ?? null,
                'responded_model' => $record['model']['responded'] ?? null,
            ]);
        }
    }

    /** @param array<string, mixed> $record */
    private function content(string $recordId, string $runId, string $sourceRecordId, array $record): void
    {
        try {
            $this->persistContent($recordId, $runId, $record);
        } catch (PDOException $exception) {
            throw ContentPersistenceFailed::fromDatabase($exception, $sourceRecordId);
        }
    }

    /** @param array<string, mixed> $record */
    private function persistContent(string $recordId, string $runId, array $record): void
    {
        $contentObject = is_string($record['content'])
            ? json_decode($record['content'], false, 512, JSON_THROW_ON_ERROR)
            : null;

        if (! $contentObject instanceof stdClass) {
            throw new RuntimeException('Validated record content must be an encoded JSON object.');
        }

        $content = get_object_vars($contentObject);

        if ($record['type'] === 'step.start') {
            foreach ($content['message_hashes'] ?? [] as $position => $hash) {
                DB::table('assay_message_references')->insertOrIgnore([
                    'record_id' => $recordId,
                    'run_id' => $runId,
                    'position' => $position,
                    'hash' => $hash,
                ]);
            }

            $newMessages = $content['new_messages'] ?? new stdClass;

            if (! $newMessages instanceof stdClass) {
                throw new RuntimeException('Validated new_messages must be an encoded JSON object.');
            }

            foreach (get_object_vars($newMessages) as $hash => $body) {
                DB::table('assay_messages')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'run_id' => $runId,
                    'hash' => $hash,
                    'body' => json_encode($body, JSON_THROW_ON_ERROR),
                ]);
            }

            if (get_object_vars($newMessages) !== []) {
                unset($content['new_messages']);
            }
        }

        if ($content !== []) {
            DB::table('assay_record_content')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'record_id' => $recordId,
                'run_id' => $runId,
                'content' => json_encode($content, JSON_THROW_ON_ERROR),
            ]);
        }

        $unresolved = DB::table('assay_message_references as reference')
            ->where('reference.run_id', $runId)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('assay_messages as message')
                    ->whereColumn('message.run_id', 'reference.run_id')
                    ->whereColumn('message.hash', 'reference.hash');
            })
            ->exists();

        DB::table('assay_runs')->where('id', $runId)->update(['content_incomplete' => $unresolved]);
    }

    public function reconcileStale(?CarbonImmutable $asOf = null, ?int $limit = null): int
    {
        $asOf ??= CarbonImmutable::now();
        $minutes = max(1, (int) config('assay.stale_after_minutes', 30));
        $limit ??= max(1, (int) config('assay.stale.batch_size', 1_000));
        $cutoff = $asOf->subMinutes($minutes)->format('Y-m-d H:i:s.uP');
        $runs = DB::table('assay_runs')
            ->whereNull('ended_at')
            ->whereNotIn('status', ['incomplete', 'failed_or_lost'])
            ->where('earliest_received_at', '<=', $cutoff)
            ->oldest('earliest_received_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'earliest_received_at']);

        foreach ($runs as $run) {
            $this->reconcileRun((string) $run->id, $asOf);
        }

        return $runs->count();
    }

    private function reconcileRun(string $runId, CarbonImmutable $asOf): void
    {
        /** @var stdClass|null $run */
        $run = DB::table('assay_runs')->where('id', $runId)->first();

        if ($run === null) {
            return;
        }

        $values = [];

        if ($run->ended_at !== null) {
            $values['status'] = $run->outcome === 'failed' ? 'failed' : 'completed';

            if ($run->started_at !== null) {
                $start = CarbonImmutable::parse((string) $run->started_at);
                $end = CarbonImmutable::parse((string) $run->ended_at);

                if ($end->greaterThanOrEqualTo($start)) {
                    $values['duration_ms'] = $this->decimal($start->diffInMicroseconds($end) / 1000);
                }
            }
        } else {
            $staleAt = CarbonImmutable::parse((string) $run->earliest_received_at)
                ->addMinutes(max(1, (int) config('assay.stale_after_minutes', 30)));
            $values['status'] = $asOf->greaterThanOrEqualTo($staleAt)
                ? ($run->operation === 'agent' || $run->operation === null ? 'incomplete' : 'failed_or_lost')
                : 'started';
        }

        if ($run->terminal_attempt !== null) {
            $values['checksum_mismatch'] = $this->checksumMismatch($runId, (int) $run->terminal_attempt);
        }

        DB::table('assay_runs')->where('id', $runId)->update($values);
    }

    private function checksumMismatch(string $runId, int $terminalAttempt): bool
    {
        /** @var string|null $attemptId */
        $attemptId = DB::table('assay_attempts')
            ->where('run_id', $runId)
            ->where('ordinal', $terminalAttempt)
            ->value('id');

        if ($attemptId === null) {
            return false;
        }

        $result = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM (
                    SELECT metric, SUM(value) AS total
                    FROM assay_usage_metrics
                    WHERE run_id = ? AND attempt_id = ? AND source = 'agent_step'
                    GROUP BY metric
                ) steps
                INNER JOIN (
                    SELECT metric, SUM(value) AS total
                    FROM assay_usage_metrics
                    WHERE run_id = ? AND attempt_id = ? AND source = 'agent_checksum'
                    GROUP BY metric
                ) checksum USING (metric)
                WHERE steps.total > checksum.total
            ) AS mismatch
            SQL, [$runId, $attemptId, $runId, $attemptId]);

        return (bool) ($result->mismatch ?? false);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>
     */
    private function present(array $source, array $mapping): array
    {
        $values = [];

        foreach ($mapping as $from => $to) {
            if (array_key_exists($from, $source)) {
                $values[$to] = $source[$from];
            }
        }

        return $values;
    }

    private function decimal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        $previousPrecision = ini_set('serialize_precision', '-1');

        if ($previousPrecision === false) {
            throw new RuntimeException('Unable to set deterministic decimal serialization precision.');
        }

        try {
            return json_encode((float) $value, JSON_THROW_ON_ERROR);
        } finally {
            ini_set('serialize_precision', $previousPrecision);
        }
    }
}
