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
        return get_object_vars($this->jsonSerialize());
    }

    public function jsonSerialize(): stdClass
    {
        $value = json_decode($this->json, false, 512, JSON_THROW_ON_ERROR);

        if (! $value instanceof stdClass) {
            throw new LogicException('Validated content must decode to an object.');
        }

        return $value;
    }

    private static function flags(): int
    {
        return JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES;
    }
}
