<?php

namespace App\Enums;

/**
 * Every error this API can return.
 *
 * The string value is the stable `code` sent to clients. Clients switch on the
 * code, never on the message, so messages can be reworded freely. HTTP status
 * and the default user-facing message live here too, so an error looks the same
 * wherever it is thrown.
 *
 * HOW TO ADD AN ERROR: add a case here, then fill in status() and message().
 * The ErrorCodeTest fails if you forget either one.
 */
enum ErrorCode: string
{
    // ---- Generic: produced by the exception handler -------------------------
    case BadRequest = 'BAD_REQUEST';
    case ValidationFailed = 'VALIDATION_FAILED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case RequestForgery = 'REQUEST_FORGERY';
    case TooManyAttempts = 'TOO_MANY_ATTEMPTS';
    case ServerError = 'SERVER_ERROR';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';

    // ---- Auth: registration -------------------------------------------------
    case NinNotFound = 'NIN_NOT_FOUND';
    case NinAlreadyRegistered = 'NIN_ALREADY_REGISTERED';
    case NinServiceUnavailable = 'NIN_SERVICE_UNAVAILABLE';
    case PhoneAlreadyRegistered = 'PHONE_ALREADY_REGISTERED';
    case OtpInvalid = 'OTP_INVALID';
    case OtpExpired = 'OTP_EXPIRED';
    case OtpDeliveryFailed = 'OTP_DELIVERY_FAILED';
    case RegistrationNotFound = 'REGISTRATION_NOT_FOUND';
    case RegistrationStepInvalid = 'REGISTRATION_STEP_INVALID';
    case RegistrationFailed = 'REGISTRATION_FAILED';

    // ---- Auth: login and password reset -------------------------------------
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
    case ResetNotFound = 'RESET_NOT_FOUND';

    // ---- Access: cards, requests, sessions --------------------------------
    case CardNotFound = 'CARD_NOT_FOUND';
    case CardNotActive = 'CARD_NOT_ACTIVE';
    case OrganisationNotVerified = 'ORGANISATION_NOT_VERIFIED';
    case AccessRequestNotFound = 'ACCESS_REQUEST_NOT_FOUND';
    case AccessRequestClosed = 'ACCESS_REQUEST_CLOSED';
    case ApprovalCodeInvalid = 'APPROVAL_CODE_INVALID';
    case SessionNotFound = 'SESSION_NOT_FOUND';
    case SessionClosed = 'SESSION_CLOSED';
    case RecordNotFound = 'RECORD_NOT_FOUND';

    /** HTTP status code sent with this error. */
    public function status(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::Unauthenticated, self::InvalidCredentials => 401,
            self::Forbidden, self::AccountSuspended, self::OrganisationNotVerified => 403,
            self::NotFound, self::NinNotFound, self::RegistrationNotFound, self::ResetNotFound,
            self::CardNotFound, self::AccessRequestNotFound, self::SessionNotFound, self::RecordNotFound => 404,
            self::MethodNotAllowed => 405,
            self::NinAlreadyRegistered, self::PhoneAlreadyRegistered, self::RegistrationStepInvalid,
            self::CardNotActive, self::AccessRequestClosed, self::SessionClosed => 409,
            self::RequestForgery => 419,
            self::ValidationFailed, self::OtpInvalid, self::OtpExpired, self::ApprovalCodeInvalid => 422,
            self::TooManyAttempts => 429,
            self::ServerError, self::RegistrationFailed => 500,
            self::ServiceUnavailable, self::NinServiceUnavailable, self::OtpDeliveryFailed => 503,
        };
    }

    /** Default message shown to the person using the app. Never contains internals. */
    public function message(): string
    {
        return match ($this) {
            self::BadRequest => "We couldn't understand that request.",
            self::ValidationFailed => 'Some of the details you entered are not valid.',
            self::Unauthenticated => 'Please log in to continue.',
            self::Forbidden => "You don't have permission to do that.",
            self::NotFound => "We couldn't find what you were looking for.",
            self::MethodNotAllowed => "That action isn't supported here.",
            self::RequestForgery => 'Your session expired. Refresh the page and try again.',
            self::TooManyAttempts => 'Too many attempts. Try again in a few minutes.',
            self::ServerError => 'Something went wrong on our side. Please try again.',
            self::ServiceUnavailable => 'The service is temporarily unavailable. Please try again shortly.',
            self::NinNotFound => "We couldn't find this NIN. Check the number and try again.",
            self::NinAlreadyRegistered => 'An account already exists for this NIN. Try logging in.',
            self::NinServiceUnavailable => "We couldn't verify your NIN right now. Please try again shortly.",
            self::PhoneAlreadyRegistered => 'This phone number is already registered. Try logging in.',
            self::OtpInvalid => "That code isn't correct. Check it and try again.",
            self::OtpExpired => 'That code has expired. Request a new one.',
            self::OtpDeliveryFailed => "We couldn't send the code. Please try again.",
            self::RegistrationNotFound => 'Your sign-up session expired. Please start again.',
            self::RegistrationStepInvalid => 'Please complete the previous step first.',
            self::RegistrationFailed => "We couldn't complete your registration.",
            self::InvalidCredentials => 'Your personal ID, email or password is incorrect.',
            self::AccountSuspended => 'This account is not active. Please contact support.',
            self::ResetNotFound => 'Your reset session expired. Please start again.',
            self::CardNotFound => "We couldn't find that card. Check the number and try again.",
            self::CardNotActive => 'This card is not active.',
            self::OrganisationNotVerified => 'Your organisation has not been verified yet.',
            self::AccessRequestNotFound => "We couldn't find that access request.",
            self::AccessRequestClosed => 'This request has already been answered or has expired.',
            self::ApprovalCodeInvalid => "That code isn't correct. Check it and try again.",
            self::SessionNotFound => "We couldn't find that session.",
            self::SessionClosed => 'This session has ended.',
            self::RecordNotFound => "We couldn't find that record.",
        };
    }
}
