<?php

namespace App\Enums;

/** Life of a physical card. Only `activated` cards can open an access request. */
enum CardStatus: string
{
    case Issued = 'issued';
    case Activated = 'activated';
    case Deactivated = 'deactivated';
}
