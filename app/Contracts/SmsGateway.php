<?php

namespace App\Contracts;

/**
 * Sends a text message. Implementations throw any exception on failure; callers
 * decide what the person sees (usually ErrorCode::OtpDeliveryFailed).
 *
 * `$phone` is the local 11-digit form (08012345678). Convert to +234 inside the
 * implementation if the vendor needs it (App\Support\Phone helps).
 */
interface SmsGateway
{
    public function send(string $phone, string $message): void;
}
