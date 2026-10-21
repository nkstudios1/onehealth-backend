<?php

namespace App\Enums;

/** Where an access request is. Only `pending` can still be answered. */
enum AccessRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
