<?php

namespace App\Enums;

/** How a request was answered. */
enum DecisionChannel: string
{
    case PatientApp = 'patient_app';
    case ContactApp = 'contact_app';
    case ContactLink = 'contact_link';
    case CodeEntered = 'code_entered';
}
