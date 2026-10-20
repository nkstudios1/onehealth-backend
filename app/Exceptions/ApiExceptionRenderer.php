<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Http\Responses\ApiResponder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Turns ANY exception into the standard error response. Wired up in
 * bootstrap/app.php. This is the only place that decides what an error looks
 * like, and the only place that decides what is safe to show.
 *
 * Rules:
 *  - Expected failures (ApiException, validation, auth, 404...) get their own code.
 *  - Anything unexpected becomes a generic 500. The real error goes to the logs,
 *    never to the client, unless APP_DEBUG is on (local development only).
 */
final class ApiExceptionRenderer
{
    public static function shouldRender(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => ApiResponder::error(
                $e->errorCode,
                $e->getMessage(),
                $e->errors,
                $e->meta,
                self::retryAfterHeader($e->meta['retryAfter'] ?? null),
            ),

            $e instanceof ValidationException => ApiResponder::error(
                ErrorCode::ValidationFailed,
                errors: $e->errors(),
            ),

            $e instanceof AuthenticationException => ApiResponder::error(ErrorCode::Unauthenticated),

            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => ApiResponder::error(ErrorCode::Forbidden),

            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiResponder::error(ErrorCode::NotFound),

            $e instanceof MethodNotAllowedHttpException => ApiResponder::error(
                ErrorCode::MethodNotAllowed,
                headers: array_intersect_key($e->getHeaders(), ['Allow' => true]),
            ),

            $e instanceof TokenMismatchException => ApiResponder::error(ErrorCode::RequestForgery),

            $e instanceof TooManyRequestsHttpException,
            ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 429) => self::tooManyRequests($e),

            $e instanceof HttpExceptionInterface => self::fromHttpStatus($e),

            default => self::serverError($e),
        };
    }

    private static function tooManyRequests(Throwable $e): JsonResponse
    {
        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];
        $retryAfter = isset($headers['Retry-After']) ? (int) $headers['Retry-After'] : null;

        return ApiResponder::error(
            ErrorCode::TooManyAttempts,
            meta: $retryAfter === null ? [] : ['retryAfter' => $retryAfter],
            headers: self::retryAfterHeader($retryAfter),
        );
    }

    private static function fromHttpStatus(HttpExceptionInterface $e): JsonResponse
    {
        $code = match ($e->getStatusCode()) {
            400 => ErrorCode::BadRequest,
            401 => ErrorCode::Unauthenticated,
            403 => ErrorCode::Forbidden,
            404 => ErrorCode::NotFound,
            405 => ErrorCode::MethodNotAllowed,
            419 => ErrorCode::RequestForgery,
            422 => ErrorCode::ValidationFailed,
            503 => ErrorCode::ServiceUnavailable,
            default => $e->getStatusCode() >= 500 ? ErrorCode::ServerError : ErrorCode::BadRequest,
        };

        return ApiResponder::error($code);
    }

    private static function serverError(Throwable $e): JsonResponse
    {
        $meta = [];

        // Local development only. APP_DEBUG must be false anywhere real.
        if (config('app.debug')) {
            $meta['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ];
        }

        return ApiResponder::error(ErrorCode::ServerError, meta: $meta);
    }

    /** @return array<string, string> */
    private static function retryAfterHeader(mixed $seconds): array
    {
        return is_numeric($seconds) ? ['Retry-After' => (string) (int) $seconds] : [];
    }
}
