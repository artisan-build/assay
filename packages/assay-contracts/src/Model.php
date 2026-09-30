<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

use ArtisanBuild\AssayContracts\Internal\Shape;

final readonly class Model
{
    public function __construct(
        public ?string $requested = null,
        public ?string $responded = null,
        public ?string $provider = null,
    ) {
        if ($this->toArray() === []) {
            throw new InvalidEnvelope('Model must contain at least one value.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            requested: Shape::optionalString($data, 'requested', 'model'),
            responded: Shape::optionalString($data, 'responded', 'model'),
            provider: Shape::optionalString($data, 'provider', 'model'),
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'requested' => $this->requested,
            'responded' => $this->responded,
            'provider' => $this->provider,
        ], static fn (?string $value): bool => $value !== null);
    }
}
