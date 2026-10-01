<?php

declare(strict_types=1);

namespace App\Authorization;

use ArtisanBuild\BuiltForCloud\UserRole;

final readonly class EffectiveAccess
{
    public function __construct(
        public bool $usage,
        public bool $content,
        public ContentAccessSource $source,
        public bool $redundant,
        public ?UserRole $role,
        public ?string $actor,
    ) {}
}
