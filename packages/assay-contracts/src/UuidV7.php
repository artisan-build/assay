<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

use Ramsey\Uuid\Rfc4122\FieldsInterface;
use Ramsey\Uuid\Uuid;
use Stringable;

final readonly class UuidV7 implements Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        if (! Uuid::isValid($value)) {
            throw new InvalidEnvelope('The value must be a UUIDv7.');
        }

        $fields = Uuid::fromString($value)->getFields();

        if (! $fields instanceof FieldsInterface || $fields->getVersion() !== 7) {
            throw new InvalidEnvelope('The value must be a UUIDv7.');
        }

        $this->value = strtolower($value);
    }

    public static function generate(): self
    {
        return new self(Uuid::uuid7()->toString());
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
