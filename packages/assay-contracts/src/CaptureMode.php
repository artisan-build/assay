<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

enum CaptureMode: string
{
    case Usage = 'usage';
    case Full = 'full';
}
