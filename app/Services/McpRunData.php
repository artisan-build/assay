<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class McpRunData
{
    /** @return array<string, mixed> */
    public function content(string $app, string $runId): array
    {
        $run = DB::table('assay_runs as run')
            ->join('assay_apps as app', 'app.id', '=', 'run.app_id')
            ->where('app.app_ref', $app)->where('run.id', $runId)
            ->first(['run.id', 'run.invocation_id', 'run.content_incomplete']);

        if ($run === null) {
            throw new NotFoundHttpException;
        }

        $records = DB::table('assay_record_content as content')
            ->join('assay_records as record', 'record.id', '=', 'content.record_id')
            ->where('content.run_id', $runId)
            ->oldest('record.occurred_at')->orderBy('record.id')->limit(500)
            ->get(['record.id', 'record.type', 'record.occurred_at', 'content.content'])
            ->map(static fn (stdClass $row): array => [
                'record_id' => (string) $row->id,
                'type' => (string) $row->type,
                'occurred_at' => (string) $row->occurred_at,
                'content' => json_decode((string) $row->content, true, flags: JSON_THROW_ON_ERROR),
            ])->all();
        $messages = DB::table('assay_messages')->where('run_id', $runId)
            ->orderBy('id')->limit(500)->get(['hash', 'body'])
            ->map(static fn (stdClass $row): array => [
                'hash' => (string) $row->hash,
                'body' => json_decode((string) $row->body, true, flags: JSON_THROW_ON_ERROR),
            ])->all();

        return [
            'egress' => 'Raw customer data is exported to the MCP client and onward to its model provider, transcript, and logs.',
            'run_id' => (string) $run->id,
            'invocation_id' => (string) $run->invocation_id,
            'content_incomplete' => (bool) $run->content_incomplete,
            'content_records' => $records,
            'messages' => $messages,
            'bounded_at' => ['content_records' => 500, 'messages' => 500],
        ];
    }

    /** @return list<array<string, string>> */
    public function search(string $app, string $query, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10000, $offset));
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';

        return array_map(static fn (stdClass $row): array => [
            'run_id' => (string) $row->run_id,
            'invocation_id' => (string) $row->invocation_id,
            'operation' => (string) ($row->operation ?? 'not_reported'),
            'agent' => (string) ($row->agent ?? 'not_reported'),
            'provider' => (string) ($row->provider ?? 'not_reported'),
            'model' => (string) ($row->responded_model ?? $row->requested_model ?? 'not_reported'),
            'status' => (string) $row->status,
        ], DB::select(<<<SQL
            SELECT DISTINCT
                run.id AS run_id,
                run.invocation_id,
                run.operation,
                run.agent,
                run.provider,
                run.requested_model,
                run.responded_model,
                run.status
            FROM assay_runs run
            INNER JOIN assay_apps app ON app.id = run.app_id
            WHERE app.app_ref = ? AND (
                EXISTS (
                    SELECT 1 FROM assay_record_content content
                    WHERE content.run_id = run.id AND content.content::text ILIKE ? ESCAPE '\\'
                ) OR EXISTS (
                    SELECT 1 FROM assay_messages message
                    WHERE message.run_id = run.id AND message.body::text ILIKE ? ESCAPE '\\'
                )
            )
            ORDER BY run.id
            LIMIT {$limit} OFFSET {$offset}
            SQL, [$app, $pattern, $pattern]));
    }

    public function appId(string $app): string
    {
        $id = DB::table('assay_apps')->where('app_ref', $app)->value('id');

        if (! is_string($id)) {
            throw new NotFoundHttpException;
        }

        return $id;
    }

    /** @return array{app: string, subject: string, matching_runs: int, action: string} */
    public function erasurePreview(string $app, string $subject): array
    {
        $appId = $this->appId($app);

        return [
            'app' => $app,
            'subject' => $subject,
            'matching_runs' => DB::table('assay_runs')->where('app_id', $appId)->where('subject', $subject)->count(),
            'action' => 'Erase content and curated items for this app-scoped subject while preserving pseudonymised usage totals.',
        ];
    }
}
