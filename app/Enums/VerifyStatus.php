<?php

namespace App\Enums;

/** Whether an organisation has been verified against the government registries. */
enum VerifyStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
}
