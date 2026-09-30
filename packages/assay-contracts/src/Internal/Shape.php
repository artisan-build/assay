<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts\Internal;

use ArtisanBuild\AssayContracts\InvalidEnvelope;
use stdClass;

/** @internal */
final class Shape
{
    /** @return array<string, mixed> */
    public static function object(mixed $value, string $path): array
    {
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }

        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidEnvelope("{$path} must be an object.");
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidEnvelope("{$path} must have string keys.");
            }

            $object[$key] = $item;
        }

        return $object;
    }

    /** @return list<mixed> */
    public static function list(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidEnvelope("{$path} must be a list.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidEnvelope("{$path}.{$key} must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function optionalString(array $data, string $key, string $path): ?string
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        if (! is_string($data[$key])) {
            throw new InvalidEnvelope("{$path}.{$key} must be a string.");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    public static function integer(array $data, string $key, string $path): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidEnvelope("{$path}.{$key} must be an integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function optionalInteger(array $data, string $key, string $path): ?int
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        if (! is_int($data[$key])) {
            throw new InvalidEnvelope("{$path}.{$key} must be an integer.");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    public static function boolean(array $data, string $key, string $path): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw new InvalidEnvelope("{$path}.{$key} must be a boolean.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function required(array $data, string $key, string $path): mixed
    {
        if (! array_key_exists($key, $data)) {
            throw new InvalidEnvelope("{$path}.{$key} is required.");
        }

        return $data[$key];
    }
}
