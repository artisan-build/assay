<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

interface TreeLifecycleRecorder
{
    public function retainTreeContext(string $invocationId): void;

    public function releaseTreeContext(string $invocationId): void;
}
