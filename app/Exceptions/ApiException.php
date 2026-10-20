<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;
use Throwable;

/**
 * The one exception to throw for any expected failure ("wrong code", "not found",
 * "too many attempts"). The exception handler turns it into the standard error
 * response, so controllers and services never build error JSON by hand.
 *
 *   throw ApiException::of(ErrorCode::OtpInvalid);
 *   throw ApiException::of(ErrorCode::PhoneAlreadyRegistered, errors: ['phone' => ['Already used']]);
 *   throw ApiException::tooManyAttempts(retryAfter: 120);
 *
 * Unexpected failures (bugs, outages) should NOT be ApiExceptions: let them
 * bubble up and the handler returns a generic 500 without leaking details.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  field => messages, for form-style errors
     * @param  array<string, mixed>  $meta  extra machine-readable info, e.g. ['retryAfter' => 120]
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly array $errors = [],
        public readonly array $meta = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?? $errorCode->message(), $errorCode->status(), $previous);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $meta
     */
    public static function of(
        ErrorCode $code,
        ?string $message = null,
        array $errors = [],
        array $meta = [],
        ?Throwable $previous = null,
    ): self {
        return new self($code, $message, $errors, $meta, $previous);
    }

    /** A 429 that tells the client how many seconds to wait. */
    public static function tooManyAttempts(int $retryAfter, ?string $message = null): self
    {
        return new self(ErrorCode::TooManyAttempts, $message, meta: ['retryAfter' => max(1, $retryAfter)]);
    }

    public function status(): int
    {
        return $this->errorCode->status();
    }
}
