<?php

namespace App\Enums;

/** Main record categories. Types inside a category are free text. */
enum RecordCategory: string
{
    case ClinicalReport = 'clinical_report';
    case Vitals = 'vitals';
    case MedicalHistory = 'medical_history';
}
