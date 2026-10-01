<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;

final readonly class DashboardTable implements JsonSerializable
{
    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $userControlledColumns
     */
    public function __construct(
        public array $headers,
        public array $rows,
        public array $userControlledColumns = [],
    ) {}

    /** @return array{headers: list<string>, rows: list<array<string, string>>} */
    public function jsonSerialize(): array
    {
        return [
            'headers' => $this->headers,
            'rows' => $this->rows,
        ];
    }
}
