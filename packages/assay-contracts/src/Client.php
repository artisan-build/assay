<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

use ArtisanBuild\AssayContracts\Internal\Shape;

final readonly class Client
{
    public function __construct(
        public string $package,
        public string $version,
    ) {
        if ($this->package === '' || $this->version === '') {
            throw new InvalidEnvelope('Client package and version must be non-empty strings.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            package: Shape::string($data, 'package', 'client'),
            version: Shape::string($data, 'version', 'client'),
        );
    }

    /** @return array{package: string, version: string} */
    public function toArray(): array
    {
        return ['package' => $this->package, 'version' => $this->version];
    }
}
