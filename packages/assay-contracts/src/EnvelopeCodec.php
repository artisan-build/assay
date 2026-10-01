<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

use ArtisanBuild\AssayContracts\Internal\Shape;
use JsonException;
use stdClass;

final class EnvelopeCodec
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES;

    public static function encode(EnvelopeV1 $envelope): string
    {
        return json_encode($envelope->toArray(), self::JSON_FLAGS);
    }

    /** @param (callable(array<string, mixed>): void)|null $inspect */
    public static function decode(string $json, ?callable $inspect = null): EnvelopeV1
    {
        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidEnvelope('Envelope JSON is malformed.', previous: $exception);
        }

        $data = Shape::object($decoded, 'envelope');

        if ($inspect !== null) {
            $inspect($data);
        }

        return EnvelopeV1::fromArray(self::normalizeContentStrings($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizeContentStrings(array $data): array
    {
        $records = $data['records'] ?? null;

        if (! is_array($records)) {
            return $data;
        }

        foreach ($records as $index => $record) {
            if ($record instanceof stdClass && property_exists($record, 'content')) {
                $rehash = ($record->type ?? null) === RecordType::StepStart->value
                    && self::containsNull($record->content);
                $record->content = self::normalizeValue($record->content);

                if ($rehash) {
                    $record->content = self::rehashMessages($record->content);
                }
            } elseif (is_array($record) && array_key_exists('content', $record)) {
                $rehash = ($record['type'] ?? null) === RecordType::StepStart->value
                    && self::containsNull($record['content']);
                $record['content'] = self::normalizeValue($record['content']);

                if ($rehash) {
                    $record['content'] = self::rehashMessages($record['content']);
                }

                $records[$index] = $record;
            }
        }

        $data['records'] = $records;

        return $data;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace("\0", "\u{FFFD}", $value);
        }

        if ($value instanceof stdClass) {
            return (object) array_map(self::normalizeValue(...), get_object_vars($value));
        }

        return is_array($value) ? array_map(self::normalizeValue(...), $value) : $value;
    }

    private static function containsNull(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, "\0");
        }

        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (self::containsNull($item)) {
                return true;
            }
        }

        return false;
    }

    private static function rehashMessages(mixed $content): mixed
    {
        $contentIsObject = $content instanceof stdClass;
        $data = $contentIsObject ? get_object_vars($content) : $content;

        if (! is_array($data)
            || ! is_array($data['message_hashes'] ?? null)
            || (! is_array($data['new_messages'] ?? null) && ! ($data['new_messages'] ?? null) instanceof stdClass)) {
            return $content;
        }

        $newMessages = $data['new_messages'] instanceof stdClass
            ? get_object_vars($data['new_messages'])
            : $data['new_messages'];
        $replacements = [];
        $messages = [];

        foreach ($newMessages as $hash => $message) {
            if (! is_string($hash)
                || (! is_array($message) && ! $message instanceof stdClass)) {
                continue;
            }

            $replacement = hash('sha256', json_encode(
                self::canonicalize($message),
                self::JSON_FLAGS | JSON_UNESCAPED_UNICODE,
            ));
            $replacements[$hash] = $replacement;
            $messages[$replacement] = $message;
        }

        $data['message_hashes'] = array_map(
            static fn (mixed $hash): mixed => is_string($hash) ? ($replacements[$hash] ?? $hash) : $hash,
            $data['message_hashes'],
        );
        $data['new_messages'] = (object) $messages;

        return $contentIsObject ? (object) $data : $data;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);

            return (object) array_map(self::canonicalize(...), $properties);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(self::canonicalize(...), $value);
    }
}
