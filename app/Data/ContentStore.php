<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ContentStore
{
    /**
     * @param  list<string>  $contentColumns
     */
    public function __construct(
        public string $table,
        public array $contentColumns,
        public string $retention,
        public string $erasure,
        public ?int $maximumResidueHours = null,
    ) {}
}
