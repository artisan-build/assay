<?php

declare(strict_types=1);

namespace App\Services;

final class ContentStoreRegistry
{
    public function recordContent(): string
    {
        return 'assay_record_content';
    }

    public function messages(): string
    {
        return 'assay_messages';
    }

    public function pendingContentAttaches(): string
    {
        return 'assay_pending_content_attaches';
    }

    /** @return list<string> */
    public function tables(): array
    {
        return [
            $this->recordContent(),
            $this->messages(),
            $this->pendingContentAttaches(),
        ];
    }
}
