<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

enum Outcome: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}
