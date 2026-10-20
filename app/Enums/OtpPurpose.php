<?php

namespace App\Enums;

/** What a one-time code is for. A code issued for one purpose never works for another. */
enum OtpPurpose: string
{
    case SignupPhone = 'signup_phone';
    case PasswordReset = 'password_reset';
}
