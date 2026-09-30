<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

enum FailureCapture: string
{
    case Complete = 'complete';
    case Truncated = 'truncated';
}
