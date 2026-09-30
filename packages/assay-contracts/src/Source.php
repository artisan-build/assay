<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

use ArtisanBuild\AssayContracts\Internal\Shape;

final readonly class Source
{
    public function __construct(
        public string $driver,
        public string $package,
        public string $version,
    ) {
        if ($this->driver === '' || $this->package === '' || $this->version === '') {
            throw new InvalidEnvelope('Source driver, package, and version must be non-empty strings.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            driver: Shape::string($data, 'driver', 'source'),
            package: Shape::string($data, 'package', 'source'),
            version: Shape::string($data, 'version', 'source'),
        );
    }

    /** @return array{driver: string, package: string, version: string} */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'package' => $this->package,
            'version' => $this->version,
        ];
    }
}
