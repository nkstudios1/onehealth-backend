<?php

namespace App\Services\Auth;

use App\Models\Patient;
use App\Models\User;
use App\Support\Identifier;

/**
 * Finds the account a person means when they type "personal ID, phone or email".
 * Shared by login and password reset so both treat identifiers identically.
 */
class AccountFinder
{
    /**
     * @param  array{type: string, value: string}|null  $identifier  result of Identifier::classify()
     */
    public function find(?array $identifier): ?User
    {
        if ($identifier === null) {
            return null;
        }

        return match ($identifier['type']) {
            Identifier::PHONE => User::where('phone', $identifier['value'])->first(),
            Identifier::EMAIL => User::where('email', $identifier['value'])->first(),
            Identifier::PERSONAL_ID => Patient::where('personal_id', $identifier['value'])->first()?->user,
            default => null,
        };
    }
}
