<?php

namespace App\Enums;

/**
 * Events written to the audit log. The log is append-only: rows are never
 * edited or deleted. Add a case here when a new module needs to record
 * something security-relevant.
 */
enum AuditEvent: string
{
    case RegistrationStarted = 'registration.started';
    case RegistrationIdentityConfirmed = 'registration.identity_confirmed';
    case RegistrationPhoneVerified = 'registration.phone_verified';
    case RegistrationCompleted = 'registration.completed';
    case RegistrationFailed = 'registration.failed';

    case OtpSent = 'otp.sent';
    case OtpFailed = 'otp.failed';

    case LoginSucceeded = 'login.succeeded';
    case LoginFailed = 'login.failed';
    case LoginLocked = 'login.locked';
    case Logout = 'logout';

    case PasswordResetRequested = 'password_reset.requested';
    case PasswordResetVerified = 'password_reset.verified';
    case PasswordResetCompleted = 'password_reset.completed';

    case CardActivated = 'card.activated';
    case AccessRequested = 'access.requested';
    case AccessEscalated = 'access.escalated';
    case AccessApproved = 'access.approved';
    case AccessDenied = 'access.denied';
    case AccessCodeFailed = 'access.code_failed';
    case SessionOpened = 'session.opened';
    case SessionCheckedOut = 'session.checked_out';
    case SessionRevoked = 'session.revoked';
    case SessionAutoClosed = 'session.auto_closed';
    case RecordCreated = 'record.created';
    case RecordVisibilityChanged = 'record.visibility_changed';
    case PatientDataViewed = 'patient_data.viewed';
}
