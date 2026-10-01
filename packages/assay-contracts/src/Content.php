<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

use JsonException;
use JsonSerializable;
use LogicException;
use stdClass;

final readonly class Content implements JsonSerializable
{
    private string $json;

    /** @param array<array-key, mixed>|stdClass $data */
    public function __construct(array|stdClass $data)
    {
        if (is_array($data) && $data !== [] && array_is_list($data)) {
            throw new InvalidEnvelope('Content must be an object.');
        }

        try {
            $json = json_encode(is_array($data) ? (object) $data : $data, self::flags());

            if ($json === false) {
                throw new InvalidEnvelope('Content could not be encoded.');
            }

            $this->json = $json;
        } catch (JsonException $exception) {
            throw new InvalidEnvelope('Content must contain only finite JSON values.', previous: $exception);
        }
    }

    public static function fromValue(mixed $value): self
    {
        if (! $value instanceof stdClass) {
            if (! is_array($value) || array_is_list($value)) {
                throw new InvalidEnvelope('content must be an object.');
            }

            return new self($value);
        }

        return new self($value);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $value = json_decode($this->json, false, 512, JSON_THROW_ON_ERROR);

        if (! $value instanceof stdClass) {
            throw new LogicException('Validated content must decode to an object.');
        }

        return array_map(self::arrayValue(...), get_object_vars($value));
    }

    public function toJson(): string
    {
        return $this->json;
    }

    public function jsonSerialize(): stdClass
    {
        $value = json_decode($this->json, false, 512, JSON_THROW_ON_ERROR);

        if (! $value instanceof stdClass) {
            throw new LogicException('Validated content must decode to an object.');
        }

        return $value;
    }

    public function validateFor(RecordType $type, ?Operation $operation, ?Outcome $outcome): void
    {
        $value = json_decode($this->json, false, 512, JSON_THROW_ON_ERROR);

        if (! $value instanceof stdClass) {
            throw new LogicException('Validated content must decode to an object.');
        }

        $data = get_object_vars($value);

        if ($data === []) {
            throw new InvalidEnvelope('Record content must not be empty.');
        }

        $allowed = match (true) {
            $type === RecordType::RunStart && $operation === Operation::Agent => ['instructions', 'tools'],
            $type === RecordType::StepStart && $operation === Operation::Agent => ['message_hashes', 'new_messages'],
            $type === RecordType::StepEnd && $operation === Operation::Agent => ['output_text', 'structured_output', 'tool_calls'],
            $type === RecordType::ToolStart && $operation === Operation::Agent => ['arguments'],
            $type === RecordType::ToolEnd && $operation === Operation::Agent => $outcome === Outcome::Failed
                ? ['result', 'exception_message']
                : ['result'],
            $type === RecordType::RunEnd && $operation === Operation::Agent && $outcome === Outcome::Failed => ['exception_message'],
            $type === RecordType::StepFail && $operation === Operation::Agent => ['exception_message'],
            $type === RecordType::RunStart => self::operationStartKeys($operation),
            $type === RecordType::RunEnd => self::operationEndKeys($operation),
            default => [],
        };

        self::allowedKeys($data, $allowed, 'content');
        self::validateFields($data);
    }

    /** @return list<string> */
    private static function operationStartKeys(?Operation $operation): array
    {
        return match ($operation) {
            Operation::Embeddings => ['inputs'],
            Operation::Image => ['prompt'],
            Operation::Audio => ['text'],
            Operation::Reranking => ['query', 'documents'],
            Operation::Classification => ['prompt', 'labels'],
            default => [],
        };
    }

    /** @return list<string> */
    private static function operationEndKeys(?Operation $operation): array
    {
        return match ($operation) {
            Operation::Image => ['count', 'dimensions'],
            Operation::Transcription => ['text'],
            Operation::Reranking => ['results'],
            Operation::Classification => ['answers'],
            default => [],
        };
    }

    /** @param array<string, mixed> $data */
    private static function validateFields(array $data): void
    {
        foreach (['instructions', 'output_text', 'exception_message', 'prompt', 'text', 'query'] as $field) {
            if (array_key_exists($field, $data) && ! is_string($data[$field])) {
                throw new InvalidEnvelope("content.{$field} must be a string.");
            }
        }

        foreach (['inputs', 'documents', 'labels'] as $field) {
            if (array_key_exists($field, $data)) {
                self::stringList($data[$field], "content.{$field}");
            }
        }

        if (array_key_exists('tools', $data)) {
            self::objectList($data['tools'], 'content.tools', ['name', 'description', 'parameters'], function (array $tool, string $path): void {
                self::stringField($tool, 'name', $path);
                self::stringField($tool, 'description', $path);
                self::objectValue($tool['parameters'], "{$path}.parameters");
            });
        }

        if (array_key_exists('tool_calls', $data)) {
            self::toolCalls($data['tool_calls'], 'content.tool_calls');
        }

        if (array_key_exists('message_hashes', $data)) {
            self::hashList($data['message_hashes'], 'content.message_hashes');
        }

        if (array_key_exists('new_messages', $data)) {
            self::messages($data['new_messages'], $data['message_hashes'] ?? null);
        }

        if (array_key_exists('count', $data) && (! is_int($data['count']) || $data['count'] < 0)) {
            throw new InvalidEnvelope('content.count must be a non-negative integer.');
        }

        if (array_key_exists('dimensions', $data)) {
            self::objectList($data['dimensions'], 'content.dimensions', ['width', 'height'], function (array $dimension, string $path): void {
                foreach (['width', 'height'] as $field) {
                    if (! is_int($dimension[$field]) || $dimension[$field] < 1) {
                        throw new InvalidEnvelope("{$path}.{$field} must be a positive integer.");
                    }
                }
            });
        }

        if (array_key_exists('results', $data)) {
            self::objectList($data['results'], 'content.results', ['index', 'score'], function (array $result, string $path): void {
                if (! is_int($result['index']) || $result['index'] < 0) {
                    throw new InvalidEnvelope("{$path}.index must be a non-negative integer.");
                }

                if (! is_int($result['score']) && ! is_float($result['score'])) {
                    throw new InvalidEnvelope("{$path}.score must be a finite number.");
                }
            });
        }
    }

    private static function messages(mixed $value, mixed $hashes): void
    {
        if (! $value instanceof stdClass) {
            throw new InvalidEnvelope('content.new_messages must be an object.');
        }

        if (! is_array($hashes) || ! array_is_list($hashes)) {
            throw new InvalidEnvelope('content.new_messages requires message_hashes.');
        }

        foreach (get_object_vars($value) as $hash => $message) {
            if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || ! in_array($hash, $hashes, true)) {
                throw new InvalidEnvelope('Every new_messages key must be a referenced lowercase sha256 hash.');
            }

            if (! $message instanceof stdClass) {
                throw new InvalidEnvelope("content.new_messages.{$hash} must be an object.");
            }

            $messageData = get_object_vars($message);
            self::allowedKeys($messageData, ['role', 'text', 'tool_calls', 'tool_call_id'], "content.new_messages.{$hash}");
            self::stringField($messageData, 'role', "content.new_messages.{$hash}");

            if (! in_array($messageData['role'], ['system', 'user', 'assistant', 'tool'], true)) {
                throw new InvalidEnvelope("content.new_messages.{$hash}.role is unsupported.");
            }

            foreach (['text', 'tool_call_id'] as $field) {
                if (array_key_exists($field, $messageData) && ! is_string($messageData[$field])) {
                    throw new InvalidEnvelope("content.new_messages.{$hash}.{$field} must be a string.");
                }
            }

            if (array_key_exists('tool_calls', $messageData)) {
                self::toolCalls($messageData['tool_calls'], "content.new_messages.{$hash}.tool_calls");
            }

            $canonical = json_encode(
                self::canonicalize($message),
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            if (! hash_equals($hash, hash('sha256', $canonical))) {
                throw new InvalidEnvelope('Every new_messages key must match the canonical message hash.');
            }
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
            ksort($value, SORT_STRING);

            return $value === [] ? new stdClass : array_map(self::canonicalize(...), $value);
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

    private static function toolCalls(mixed $value, string $path): void
    {
        self::objectList($value, $path, ['id', 'name', 'arguments'], function (array $call, string $itemPath): void {
            self::stringField($call, 'id', $itemPath);
            self::stringField($call, 'name', $itemPath);
        });
    }

    private static function hashList(mixed $value, string $path): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidEnvelope("{$path} must be a list.");
        }

        foreach ($value as $hash) {
            if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new InvalidEnvelope("{$path} must contain lowercase sha256 hashes.");
            }
        }
    }

    private static function stringList(mixed $value, string $path): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidEnvelope("{$path} must be a list of strings.");
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidEnvelope("{$path} must contain only strings.");
            }
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  callable(array<string, mixed>, string): void  $validate
     */
    private static function objectList(mixed $value, string $path, array $keys, callable $validate): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidEnvelope("{$path} must be a list.");
        }

        foreach ($value as $index => $item) {
            if (! $item instanceof stdClass) {
                throw new InvalidEnvelope("{$path}.{$index} must be an object.");
            }

            $item = get_object_vars($item);
            self::allowedKeys($item, $keys, "{$path}.{$index}");

            foreach ($keys as $key) {
                if (! array_key_exists($key, $item)) {
                    throw new InvalidEnvelope("{$path}.{$index}.{$key} is required.");
                }
            }

            $validate($item, "{$path}.{$index}");
        }
    }

    /** @param array<string, mixed> $data */
    private static function stringField(array $data, string $field, string $path): void
    {
        if (! array_key_exists($field, $data) || ! is_string($data[$field])) {
            throw new InvalidEnvelope("{$path}.{$field} must be a string.");
        }
    }

    private static function objectValue(mixed $value, string $path): void
    {
        if (! $value instanceof stdClass) {
            throw new InvalidEnvelope("{$path} must be an object.");
        }
    }

    private static function arrayValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $data = get_object_vars($value);

            return $data === [] ? $value : array_map(self::arrayValue(...), $data);
        }

        return is_array($value) ? array_map(self::arrayValue(...), $value) : $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private static function allowedKeys(array $data, array $allowed, string $path): void
    {
        $unknown = array_diff(array_keys($data), $allowed);

        if ($unknown !== []) {
            throw new InvalidEnvelope("{$path} contains unsupported keys.");
        }

        if ($allowed === []) {
            throw new InvalidEnvelope('Content is not allowed for this record.');
        }
    }

    private static function flags(): int
    {
        return JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES;
    }
}
