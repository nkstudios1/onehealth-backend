<?php

namespace App\Support;

/**
 * Nigerian phone numbers. The patient app sends the 11-digit local form
 * (08012345678). We also accept +234 / 234 forms and store only the local form,
 * so the same number can never be saved two different ways.
 */
final class Phone
{
    /** Same rule as PHONE_REGEX in the patient app. */
    public const PATTERN = '/^0\d{10}$/';

    public static function normalise(string $raw): string
    {
        $digits = preg_replace('/[\s\-().]/', '', trim($raw)) ?? '';

        if (preg_match('/^\+?234(\d{10})$/', $digits, $match) === 1) {
            return '0'.$match[1];
        }

        return $digits;
    }

    public static function isValid(string $phone): bool
    {
        return preg_match(self::PATTERN, $phone) === 1;
    }
}
