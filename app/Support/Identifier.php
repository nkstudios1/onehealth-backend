<?php

namespace App\Support;

/**
 * What a person typed into the "personal ID, phone or email" box.
 *
 *   08012345678       -> phone
 *   chidi@mail.com    -> email (lower-cased)
 *   4829175036        -> personal ID (10 digits, never starts with 0)
 *
 * Returns null for anything else, so callers treat it as "no such account"
 * without ever touching the database.
 */
final class Identifier
{
    public const PHONE = 'phone';

    public const EMAIL = 'email';

    public const PERSONAL_ID = 'personal_id';

    /** @return array{type: string, value: string}|null */
    public static function classify(string $raw): ?array
    {
        $raw = trim($raw);

        if (str_contains($raw, '@')) {
            return filter_var($raw, FILTER_VALIDATE_EMAIL) !== false
                ? ['type' => self::EMAIL, 'value' => mb_strtolower($raw)]
                : null;
        }

        $phone = Phone::normalise($raw);
        if (Phone::isValid($phone)) {
            return ['type' => self::PHONE, 'value' => $phone];
        }

        if (preg_match('/^[1-9]\d{9}$/', $raw) === 1) {
            return ['type' => self::PERSONAL_ID, 'value' => $raw];
        }

        return null;
    }
}
