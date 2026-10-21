<?php

namespace App\Enums;

/** Account state (schema v2: user.status). Only `Active` accounts can log in. */
enum AccountStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case Deleted = 'deleted';
}
