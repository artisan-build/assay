<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

use DateTimeImmutable;
use DateTimeInterface;
use Stringable;

final readonly class Timestamp implements Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d\.\d{6}(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value) !== 1) {
            throw new InvalidEnvelope('The timestamp must be RFC3339 with six fractional digits.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidEnvelope('The timestamp is not a valid date and time.');
        }

        $this->value = $value;
    }

    public static function fromDateTime(DateTimeInterface $value): self
    {
        return new self($value->format('Y-m-d\TH:i:s.uP'));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
