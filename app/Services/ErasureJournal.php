<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;
use RuntimeException;
use Throwable;

final class ErasureJournal
{
    private const int CURSOR_LENGTH = 53;

    /** @param array<string, string|int> $entry */
    public function append(array $entry): void
    {
        $validated = $this->validated($entry);
        $cursor = $this->entryCursor($validated);
        $path = $this->entryPath($cursor);
        $written = $this->disk()->put($path, json_encode($validated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if (! $written) {
            throw new RuntimeException('The erasure journal entry could not be persisted.');
        }
    }

    /**
     * @return array{
     *     items: list<array{cursor: string, entry: null|array{schema: int, entry_id: string, erasure_id: string, app_id: string, key_version: string, lookup_key: string, tombstone: string, cutoff_at: string, recorded_at: string}}>,
     *     next_cursor: ?string,
     *     has_more: bool
     * }
     */
    public function newerThan(CarbonImmutable $snapshot, int $limit, ?string $cursor = null): array
    {
        if ($limit < 1) {
            throw new RuntimeException('The erasure journal page size must be positive.');
        }

        if ($cursor !== null && preg_match('/^\d{20}-[a-f0-9]{32}$/', $cursor) !== 1) {
            throw new RuntimeException('The erasure journal cursor is invalid.');
        }

        $snapshotCursor = $snapshot->utc()->format('YmdHisu').'~';
        $after = $cursor === null || $snapshotCursor > $cursor ? $snapshotCursor : $cursor;
        $items = [];
        $hasMore = false;

        foreach ($this->pathsAfter($after) as $next) {
            if (count($items) === $limit) {
                $hasMore = true;

                break;
            }

            try {
                $decoded = json_decode($this->disk()->get($next['path']), true, 32, JSON_THROW_ON_ERROR);
                $entry = $this->validated($decoded);

                if ($this->entryCursor($entry) !== $next['cursor']) {
                    throw new RuntimeException('The erasure journal path does not match its entry.');
                }
            } catch (Throwable) {
                $entry = null;
            }

            $items[] = ['cursor' => $next['cursor'], 'entry' => $entry];
        }

        return [
            'items' => $items,
            'next_cursor' => $items === [] ? $cursor : $items[array_key_last($items)]['cursor'],
            'has_more' => $hasMore,
        ];
    }

    /** @return Generator<int, array{cursor: string, path: string}> */
    private function pathsAfter(string $cursor): Generator
    {
        yield from $this->walk($this->prefix().'/entries', '', $cursor, true);
    }

    /** @return Generator<int, array{cursor: string, path: string}> */
    private function walk(string $path, string $key, string $cursor, bool $bounded): Generator
    {
        if (strlen($key) === self::CURSOR_LENGTH) {
            if (! $bounded || $key > $cursor) {
                yield ['cursor' => $key, 'path' => $path.'/entry.json'];
            }

            return;
        }

        $children = [];

        foreach ($this->disk()->getDriver()->listContents($path, false) as $attributes) {
            if ($attributes instanceof StorageAttributes && $attributes->isDir()) {
                $children[] = basename($attributes->path());
            }
        }

        sort($children);
        $cursorCharacter = $bounded ? ($cursor[strlen($key)] ?? null) : null;

        foreach ($children as $child) {
            if (strlen($child) !== 1 || preg_match('/^[a-f0-9-]$/', $child) !== 1) {
                continue;
            }

            if ($cursorCharacter !== null && $child < $cursorCharacter) {
                continue;
            }

            $childBounded = $bounded && $child === $cursorCharacter;

            yield from $this->walk($path.'/'.$child, $key.$child, $cursor, $childBounded);
        }
    }

    /**
     * @param  array{entry_id: string, recorded_at: string}  $entry
     */
    private function entryCursor(array $entry): string
    {
        $entryId = strtolower(str_replace('-', '', $entry['entry_id']));

        if (preg_match('/^[a-f0-9]{32}$/', $entryId) !== 1) {
            throw new RuntimeException('Invalid erasure journal entry id.');
        }

        return CarbonImmutable::parse($entry['recorded_at'])->utc()->format('YmdHisu').'-'.$entryId;
    }

    private function entryPath(string $cursor): string
    {
        return $this->prefix().'/entries/'.implode('/', str_split($cursor)).'/entry.json';
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
