<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class SubjectErasureHasher
{
    private const string DOMAIN = 'assay-subject-erasure';

    /** @return array{version: string, lookup_key: string, tombstone: string} */
    public function active(string $subject): array
    {
        $version = $this->activeVersion();
        $digest = $this->digest($version, $subject);

        return [
            'version' => $version,
            'lookup_key' => $digest,
            'tombstone' => 'deleted:'.$digest,
        ];
    }

    /** @return list<array{version: string, lookup_key: string, tombstone: string}> */
    public function candidates(string $subject): array
    {
        if (str_starts_with($subject, 'deleted:') && preg_match('/^deleted:[a-f0-9]{64}$/', $subject) === 1) {
            return [];
        }

        $candidates = [];

        foreach ($this->keys() as $version => $key) {
            $digest = hash_hmac('sha256', self::DOMAIN."\0".$subject, $key);
            $candidates[] = [
                'version' => $version,
                'lookup_key' => $digest,
                'tombstone' => 'deleted:'.$digest,
            ];
        }

        return $candidates;
    }

    public function matches(string $version, string $subject, string $lookupKey): bool
    {
        return hash_equals($lookupKey, $this->digest($version, $subject));
    }

    /** @return list<string> */
    public function lockTokens(string $subject): array
    {
        if (str_starts_with($subject, 'deleted:')) {
            return [$subject];
        }

        $tokens = array_map(
            static fn (array $candidate): string => $candidate['tombstone'],
            $this->candidates($subject),
        );
        sort($tokens);

        return $tokens;
    }

    private function digest(string $version, string $subject): string
    {
        $keys = $this->keys();

        if (! isset($keys[$version])) {
            throw new RuntimeException('The configured erasure key version is unavailable.');
        }

        return hash_hmac('sha256', self::DOMAIN."\0".$subject, $keys[$version]);
    }

    private function activeVersion(): string
    {
        $version = config('assay.erasure.active_key_version');

        if (! is_string($version) || $version === '' || ! isset($this->keys()[$version])) {
            throw new RuntimeException('The active erasure key version is invalid.');
        }

        return $version;
    }

    /** @return array<string, string> */
    private function keys(): array
    {
        $configured = config('assay.erasure.keys');

        if (! is_array($configured) || $configured === []) {
            throw new RuntimeException('At least one erasure key must be configured.');
        }

        $keys = [];

        foreach ($configured as $version => $key) {
            if (! is_string($version) || $version === '' || ! is_string($key) || $key === '') {
                throw new RuntimeException('Erasure key versions and values must be non-empty strings.');
            }

            if (str_starts_with($key, 'base64:')) {
                $decoded = base64_decode(substr($key, 7), true);

                if ($decoded === false) {
                    throw new RuntimeException('An erasure key is not valid base64.');
                }

                $key = $decoded;
            }

            if (strlen($key) < 32) {
                throw new RuntimeException('Erasure keys must contain at least 32 bytes.');
            }

            $keys[$version] = $key;
        }

        return $keys;
    }
}
