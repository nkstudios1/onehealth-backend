<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The id of the request being handled. It appears in every response
 * (`meta.requestId` and the `X-Request-Id` header) and in every log line, so a
 * support ticket that quotes it can be traced through the logs.
 */
final class RequestId
{
    private static ?string $current = null;

    public static function set(string $id): void
    {
        self::$current = $id;
    }

    public static function get(): string
    {
        return self::$current ??= (string) Str::uuid();
    }

    public static function reset(): void
    {
        self::$current = null;
    }
}
