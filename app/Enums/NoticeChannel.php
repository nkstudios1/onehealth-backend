<?php

namespace App\Enums;

/** How a person was told about an access request. */
enum NoticeChannel: string
{
    case App = 'app';
    case Sms = 'sms';
    case Email = 'email';
}
