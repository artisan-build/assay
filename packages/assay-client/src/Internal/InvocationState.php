<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayClient\ParentLink;

/** @internal Source-agnostic state for one live invocation. */
final class InvocationState
{
    /** @var array<string, int> */
    private array $attempts = [];

    /** @var array<string, ParentLink|null> */
    private array $parents = [];

    public function begin(string $invocationId, ?ParentLink $parent): int
    {
        $this->parents[$invocationId] = $parent;

        return $this->attempts[$invocationId] = ($this->attempts[$invocationId] ?? 0) + 1;
    }

    public function attempt(string $invocationId): int
    {
        return $this->attempts[$invocationId] ?? 1;
    }

    public function parent(string $invocationId): ?ParentLink
    {
        return $this->parents[$invocationId] ?? null;
    }

    public function finish(string $invocationId): void
    {
        unset($this->attempts[$invocationId], $this->parents[$invocationId]);
    }
}
