<?php

declare(strict_types=1);

namespace App\Authorization;

enum AssayCredentialAbility: string
{
    case Usage = 'assay.usage';
    case Content = 'assay.content';
}
