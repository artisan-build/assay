<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ContentPersistenceFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use stdClass;

final class ContentPersistence
{
    public function __construct(private readonly ContentStoreRegistry $stores) {}

    /** @param array<string, mixed> $record */
    public function persist(string $recordId, string $runId, string $sourceRecordId, array $record): void
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
                DB::table($this->stores->messages())->insertOrIgnore([
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
            DB::table($this->stores->recordContent())->insertOrIgnore([
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
                    ->from($this->stores->messages().' as message')
                    ->whereColumn('message.run_id', 'reference.run_id')
                    ->whereColumn('message.hash', 'reference.hash');
            })
            ->exists();

        DB::table('assay_runs')->where('id', $runId)->update(['content_incomplete' => $unresolved]);
    }
}
