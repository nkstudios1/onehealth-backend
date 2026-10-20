<?php

namespace App\Support;

/**
 * Hides most of a phone number or email so it is safe to show on screen
 * ("we sent a code to 080****5678"). Same output as the patient app's mock.
 */
final class Mask
{
    public static function destination(string $value): string
    {
        if (str_contains($value, '@')) {
            [$name, $domain] = explode('@', $value, 2);

            return mb_substr($name, 0, 2).'***@'.$domain;
        }

        return substr($value, 0, 3).'****'.substr($value, -4);
    }
}
