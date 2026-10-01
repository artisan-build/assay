<?php

declare(strict_types=1);

namespace App\Enums;

use ArtisanBuild\BuiltForCloud\Audit\AppAction as AppActionContract;

enum AppAction: string implements AppActionContract
{
    case ContentAccessOverrideSet = 'content-access-override-set';
    case ContentAccessOverrideReset = 'content-access-override-reset';
}
