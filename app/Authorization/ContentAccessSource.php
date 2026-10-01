<?php

declare(strict_types=1);

namespace App\Authorization;

enum ContentAccessSource: string
{
    case NotAdmitted = 'not_admitted';
    case Owner = 'owner';
    case RoleDefault = 'role_default';
    case Override = 'override';
}
