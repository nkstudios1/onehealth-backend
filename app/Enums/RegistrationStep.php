<?php

namespace App\Enums;

/**
 * Where a half-finished sign-up is. The server enforces this order, so a client
 * cannot skip a step (for example create an account without verifying the phone).
 *
 *   Started -> IdentityConfirmed -> PhoneVerified -> ContactsSaved -> (account created, row deleted)
 */
enum RegistrationStep: string
{
    case Started = 'started';
    case IdentityConfirmed = 'identity_confirmed';
    case PhoneVerified = 'phone_verified';
    case ContactsSaved = 'contacts_saved';

    public function position(): int
    {
        return match ($this) {
            self::Started => 1,
            self::IdentityConfirmed => 2,
            self::PhoneVerified => 3,
            self::ContactsSaved => 4,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->position() >= $other->position();
    }
}
