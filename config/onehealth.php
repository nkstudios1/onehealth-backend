<?php

/**
 * OneHealth settings. Every number that product or security might want to tune
 * lives here (and in .env), never inside the code that uses it.
 */
return [

    /*
    | Version of the terms and privacy notice shown at sign-up. Stored with the
    | patient's consent so we can prove which text they agreed to. Bump it when
    | the text changes.
    */
    'consent_version' => env('CONSENT_VERSION', '2026-10'),

    'registration' => [
        // How long a half-finished sign-up stays valid. Every step pushes it forward.
        'ttl_minutes' => (int) env('REGISTRATION_TTL_MINUTES', 60),
    ],

    'otp' => [
        // Must match OTP_LENGTH in the patient app (lib/constants.ts). Design shows 4 digits.
        'length' => (int) env('OTP_LENGTH', 4),
        // How long a code works.
        'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 600),
        // How long before the person may ask for another code (sent to the app as resendAfterSeconds).
        'resend_after_seconds' => (int) env('OTP_RESEND_AFTER_SECONDS', 300),
        // Wrong guesses allowed per code. After this the code is dead and a new one is needed.
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        // SMS bombing guard: codes we will send to one phone number per hour, across all sign-ups.
        'max_sends_per_phone_per_hour' => (int) env('OTP_MAX_SENDS_PER_HOUR', 5),
        // LOCAL AND TESTING ONLY. When set, every code is this value, so developers don't read logs.
        // The app refuses to issue codes if this is set in production.
        'fixed_code' => env('OTP_FIXED_CODE'),
    ],

    'login' => [
        // Wrong passwords allowed for one identifier from one IP, then locked for decay_minutes.
        'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        // Wrong passwords allowed for one identifier from ANY IP (stops a spread-out guessing attack).
        'max_attempts_any_ip' => (int) env('LOGIN_MAX_ATTEMPTS_ANY_IP', 20),
        'decay_minutes' => (int) env('LOGIN_DECAY_MINUTES', 15),
    ],

    'password_reset' => [
        // How long a reset attempt (the code step) stays valid.
        'ttl_minutes' => (int) env('PASSWORD_RESET_TTL_MINUTES', 30),
        // How long the proof-of-code token lasts once the code is verified.
        'token_ttl_minutes' => (int) env('PASSWORD_RESET_TOKEN_TTL_MINUTES', 15),
        // Reset requests allowed per identifier per hour.
        'max_requests_per_hour' => (int) env('PASSWORD_RESET_MAX_PER_HOUR', 5),
        // Tell the person (SMS or email) after their password is changed.
        'notify_on_change' => (bool) env('PASSWORD_RESET_NOTIFY', true),
    ],

    'password' => [
        // Same rules as the patient app's signup form (letter + number) plus a hard maximum
        // (bcrypt silently ignores anything past 72 bytes).
        'min_length' => 8,
        'max_length' => 64,
        // Reject passwords found in known data breaches (calls haveibeenpwned). Production only.
        'check_breaches' => (bool) env('PASSWORD_CHECK_BREACHES', false),
    ],

    'nin' => [
        // 'fake' = built-in test people (development). Add a real driver, then set this.
        'driver' => env('NIN_DRIVER', 'fake'),
        // Secret used to hash NINs. The raw NIN is never stored. NEVER change it once patients
        // exist (every stored hash would stop matching). Generate with:
        //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        'hash_key' => env('NIN_HASH_KEY'),
    ],

    'sms' => [
        // 'log' = write the message to the log (development). Production needs a real driver.
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    'access' => [
        // How long the patient has to answer before the request also goes to their emergency contacts.
        'patient_wait_seconds' => (int) env('ACCESS_PATIENT_WAIT_SECONDS', 120),
        // How long a request can be answered. After this it expires and the hospital must scan again.
        'request_ttl_minutes' => (int) env('ACCESS_REQUEST_TTL_MINUTES', 30),
        // Digits in the code a contact reads out to the hospital.
        'code_length' => 6,
        // Wrong codes allowed per request, then the request is dead.
        'code_max_attempts' => (int) env('ACCESS_CODE_MAX_ATTEMPTS', 5),
        // Scan limits, so a found card or a rogue account cannot spam patients.
        'max_requests_per_card_per_hour' => (int) env('ACCESS_MAX_PER_CARD_HOUR', 5),
        'max_requests_per_org_per_hour' => (int) env('ACCESS_MAX_PER_ORG_HOUR', 120),
        // A session nobody checked out of closes by itself after this many days.
        'session_auto_close_days' => (int) env('SESSION_AUTO_CLOSE_DAYS', 3),
        // Base URL of the page contacts open from the SMS link (the patient app).
        'approval_link_base' => env('APPROVAL_LINK_BASE', 'http://localhost:3001/approve'),
    ],
];
