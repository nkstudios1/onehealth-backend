<?php

namespace App\Http\Responses;

use App\Enums\ErrorCode;
use App\Support\RequestId;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Builds the two response shapes this API ever returns. Everything that sends
 * JSON (controllers through the ApiResponse trait, the exception handler)
 * goes through here, so the shape cannot drift.
 *
 * SUCCESS
 *   { "success": true,  "message": "OK", "data": {...} | [...] | null,
 *     "meta": { "requestId": "..." } }
 *
 * ERROR
 *   { "success": false, "message": "Human readable", "code": "OTP_INVALID",
 *     "errors": { "field": ["..."] } | null,
 *     "meta": { "requestId": "...", "retryAfter": 120 } }
 *
 * Keys are camelCase on the wire. Rename in Resources, not in the database.
 */
final class ApiResponder
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200,
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => self::normalise($data),
            'meta' => self::meta($meta),
        ], $status, $headers, self::JSON_FLAGS);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function error(
        ErrorCode $code,
        ?string $message = null,
        array $errors = [],
        array $meta = [],
        array $headers = [],
        ?int $status = null,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message ?? $code->message(),
            'code' => $code->value,
            'errors' => $errors === [] ? null : $errors,
            'meta' => self::meta($meta),
        ], $status ?? $code->status(), $headers, self::JSON_FLAGS);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function meta(array $extra): array
    {
        return ['requestId' => RequestId::get(), ...$extra];
    }

    private static function normalise(mixed $data): mixed
    {
        if ($data instanceof JsonResource) {
            return $data->resolve(request());
        }

        if ($data instanceof Arrayable) {
            return $data->toArray();
        }

        return $data;
    }
}
