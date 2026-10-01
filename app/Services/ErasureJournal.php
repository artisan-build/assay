<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ErasureJournal
{
    /** @param array<string, string|int> $entry */
    public function append(array $entry): void
    {
        $path = $this->prefix().'/'.$entry['app_id'].'/'.$entry['entry_id'].'.json';
        $written = $this->disk()->put($path, json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if (! $written) {
            throw new RuntimeException('The erasure journal entry could not be persisted.');
        }
    }

    /**
     * @return array{entries: list<array{schema: int, entry_id: string, erasure_id: string, app_id: string, key_version: string, lookup_key: string, tombstone: string, cutoff_at: string, recorded_at: string}>, invalid: int, remaining: int}
     */
    public function newerThan(CarbonImmutable $snapshot, int $limit, int $offset = 0): array
    {
        $files = $this->disk()->allFiles($this->prefix());
        sort($files);
        $entries = [];
        $invalid = 0;

        foreach ($files as $file) {
            try {
                $decoded = json_decode($this->disk()->get($file), true, 32, JSON_THROW_ON_ERROR);
                $entry = $this->validated($decoded);

                if (CarbonImmutable::parse($entry['recorded_at'])->greaterThan($snapshot)) {
                    $entries[] = $entry;
                }
            } catch (\Throwable) {
                $invalid++;
            }
        }

        usort($entries, static fn (array $left, array $right): int => [$left['recorded_at'], $left['entry_id']] <=> [$right['recorded_at'], $right['entry_id']]);
        $remaining = max(0, count($entries) - $offset - $limit);

        return [
            'entries' => array_values(array_slice($entries, $offset, $limit)),
            'invalid' => $invalid,
            'remaining' => $remaining,
        ];
    }

    private function disk(): FilesystemAdapter
    {
        $disk = config('assay.erasure.journal_disk');

        if (! is_string($disk) || $disk === '') {
            throw new RuntimeException('The erasure journal disk is invalid.');
        }

        return Storage::disk($disk);
    }

    private function prefix(): string
    {
        $prefix = config('assay.erasure.journal_prefix');

        if (! is_string($prefix) || trim($prefix, '/') === '') {
            throw new RuntimeException('The erasure journal prefix is invalid.');
        }

        return trim($prefix, '/');
    }

    /**
     * @return array{schema: int, entry_id: string, erasure_id: string, app_id: string, key_version: string, lookup_key: string, tombstone: string, cutoff_at: string, recorded_at: string}
     */
    private function validated(mixed $decoded): array
    {
        if (! is_array($decoded) || ($decoded['schema'] ?? null) !== 1) {
            throw new RuntimeException('Invalid erasure journal schema.');
        }

        foreach (['entry_id', 'erasure_id', 'app_id', 'key_version', 'lookup_key', 'tombstone', 'cutoff_at', 'recorded_at'] as $field) {
            if (! isset($decoded[$field]) || ! is_string($decoded[$field]) || $decoded[$field] === '') {
                throw new RuntimeException('Invalid erasure journal entry.');
            }
        }

        if (preg_match('/^[a-f0-9]{64}$/', $decoded['lookup_key']) !== 1
            || $decoded['tombstone'] !== 'deleted:'.$decoded['lookup_key']) {
            throw new RuntimeException('Invalid erasure journal identity.');
        }

        CarbonImmutable::parse($decoded['cutoff_at']);
        CarbonImmutable::parse($decoded['recorded_at']);

        /** @var array{schema: int, entry_id: string, erasure_id: string, app_id: string, key_version: string, lookup_key: string, tombstone: string, cutoff_at: string, recorded_at: string} $decoded */
        return $decoded;
    }
}
