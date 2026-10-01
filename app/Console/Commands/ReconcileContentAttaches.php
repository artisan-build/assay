<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ContentAttachAdmission;
use Illuminate\Console\Command;

final class ReconcileContentAttaches extends Command
{
    protected $signature = 'assay:reconcile-content-attaches
                            {--limit= : Maximum number of actionable held attaches to reconcile}';

    protected $description = 'Apply held content attaches whose targets arrived and drop expired orphans';

    public function handle(ContentAttachAdmission $admission): int
    {
        $option = $this->option('limit');
        $valid = $option === null
            || (is_string($option) && ctype_digit($option) && (int) $option > 0);

        if (! $valid) {
            $this->components->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }

        $result = $admission->reconcilePending(limit: $option === null ? null : (int) $option);
        $this->components->info("{$result['applied']} held attaches applied; {$result['dropped']} expired orphans dropped.");

        return self::SUCCESS;
    }
}
