<?php

namespace App\Enums;

/** What a session may read. Limited = public records only. */
enum AccessLevel: string
{
    case Limited = 'limited';
    case Full = 'full';
}
