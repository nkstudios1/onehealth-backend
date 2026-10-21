<?php

namespace App\Enums;

/** Who may read a record when a limited session is open. Set only by the patient. */
enum RecordVisibility: string
{
    case Public = 'public';
    case Private = 'private';
}
