<?php

namespace App\Support;

use RuntimeException;

/**
 * Keyed hashing (HMAC-SHA256). Unlike a plain hash, nobody can rebuild the
 * values by guessing without the secret key. Used for:
 *
 *  - NINs: only the hash is stored (schema: patient.nin_hash). A NIN has 11
 *    digits, so a plain SHA-256 could be reversed in minutes. HMAC with a secret
 *    key cannot.
 *  - One-time codes: stored hashed, so a database leak doesn't expose live codes.
 *  - Rate-limit keys: so phone numbers and emails never appear in cache keys.
 */
final class Hmac
{
    public static function nin(string $nin): string
    {
        $key = config('onehealth.nin.hash_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException(
                'NIN_HASH_KEY is not set. Generate one with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"'
            );
        }

        return hash_hmac('sha256', $nin, $key);
    }

    /** One-time code, bound to the sign-up or reset it belongs to. */
    public static function otp(string $subjectId, string $code): string
    {
        return self::withAppKey('otp|'.$subjectId.'|'.$code);
    }

    /** Opaque, stable id for something sensitive (identifier, token) when it must be looked up or keyed. */
    public static function lookup(string $namespace, string $value): string
    {
        return self::withAppKey($namespace.'|'.$value);
    }

    private static function withAppKey(string $data): string
    {
        return hash_hmac('sha256', $data, (string) config('app.key'));
    }
}
