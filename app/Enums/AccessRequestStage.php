<?php

namespace App\Enums;

/** Who is being asked: the patient first, then (if they do not answer) their emergency contacts. */
enum AccessRequestStage: string
{
    case Patient = 'patient';
    case Contacts = 'contacts';
}
