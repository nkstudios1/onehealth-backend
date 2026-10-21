<?php

namespace App\Enums;

/** State of a care session. */
enum CareSessionStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case CheckedOut = 'checked_out';
}
