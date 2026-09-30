<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

enum Approval: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Other = 'other';
}
