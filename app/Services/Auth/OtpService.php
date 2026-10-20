<?php

namespace App\Services\Auth;

use App\Contracts\SmsGateway;
use App\Enums\AuditEvent;
use App\Enums\ErrorCode;
use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Models\OtpCode;
use App\Support\Audit;
use App\Support\Hmac;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

/**
 * Issues and checks one-time codes. Used by sign-up and password reset; any
 * future "confirm it's really you" flow should use it too.
 *
 *   $code = $otp->issue(OtpPurpose::SignupPhone, $registration->id);
 *   $otp->verify(OtpPurpose::SignupPhone, $registration->id, $input);   // throws if wrong
 *
 * Safety rules enforced here, so callers can't forget them:
 *  - only an HMAC of the code is stored;
 *  - a new code kills the previous one (only one live code per subject);
 *  - 5 wrong guesses kill the code (an attacker can't try all 10,000 four-digit codes);
 *  - a code issued for one purpose/subject never works for another.
 */
class OtpService
{
    public function __construct(private readonly SmsGateway $sms) {}

    /** Create a fresh code. Returns the plain code, which must only ever be sent to the person. */
    public function issue(OtpPurpose $purpose, string $subjectId): string
    {
        $fixed = config('onehealth.otp.fixed_code');

        if ($fixed !== null && $fixed !== '' && app()->isProduction()) {
            throw new RuntimeException('OTP_FIXED_CODE must not be set in production.');
        }

        $length = (int) config('onehealth.otp.length');
        $code = ($fixed !== null && $fixed !== '')
            ? (string) $fixed
            : str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($purpose, $subjectId, $code) {
            OtpCode::query()
                ->where('purpose', $purpose->value)
                ->where('subject_id', $subjectId)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            OtpCode::create([
                'purpose' => $purpose,
                'subject_id' => $subjectId,
                'code_hash' => Hmac::otp($subjectId, $code),
                'attempts' => 0,
                'expires_at' => now()->addSeconds((int) config('onehealth.otp.ttl_seconds')),
                'created_at' => now(),
            ]);
        });

        return $code;
    }

    /**
     * Check a code. Returns normally on success (and uses the code up).
     *
     * @throws ApiException OTP_INVALID (wrong, may retry) or OTP_EXPIRED (dead, ask for a new one)
     */
    public function verify(OtpPurpose $purpose, string $subjectId, string $input): void
    {
        $max = (int) config('onehealth.otp.max_attempts');

        // The wrong-guess counter must be saved even though we then throw, so
        // the work happens inside the transaction and the throwing happens after it.
        $outcome = DB::transaction(function () use ($purpose, $subjectId, $input, $max): string {
            $otp = OtpCode::query()
                ->where('purpose', $purpose->value)
                ->where('subject_id', $subjectId)
                ->whereNull('consumed_at')
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            if ($otp === null || $otp->expires_at->isPast() || $otp->attempts >= $max) {
                return 'expired';
            }

            if (hash_equals($otp->code_hash, Hmac::otp($subjectId, $input))) {
                $otp->update(['consumed_at' => now()]);

                return 'ok';
            }

            $otp->attempts++;
            if ($otp->attempts >= $max) {
                $otp->consumed_at = now();
            }
            $otp->save();

            return 'invalid';
        });

        if ($outcome === 'ok') {
            return;
        }

        Audit::record(AuditEvent::OtpFailed, null, ['purpose' => $purpose->value, 'reason' => $outcome]);

        throw new ApiException($outcome === 'invalid' ? ErrorCode::OtpInvalid : ErrorCode::OtpExpired);
    }

    /**
     * Issue a code and text it. Caps how many texts one phone number can be sent per
     * hour (stops someone using us to spam a stranger, and runs up our SMS bill).
     *
     * @throws ApiException TOO_MANY_ATTEMPTS, or OTP_DELIVERY_FAILED if the SMS provider fails
     */
    public function sendBySms(OtpPurpose $purpose, string $subjectId, string $phone): void
    {
        $key = 'otp-send:'.Hmac::lookup('phone', $phone);
        $max = (int) config('onehealth.otp.max_sends_per_phone_per_hour');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 3600);

        $code = $this->issue($purpose, $subjectId);
        $minutes = (int) ceil(((int) config('onehealth.otp.ttl_seconds')) / 60);

        try {
            $this->sms->send(
                $phone,
                "Your OneHealth code is {$code}. It expires in {$minutes} minutes. Never share it with anyone.",
            );
        } catch (Throwable $e) {
            // Kill the code we could not deliver, log the cause (never the code), and show a safe message.
            OtpCode::query()
                ->where('purpose', $purpose->value)
                ->where('subject_id', $subjectId)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            Log::error('SMS delivery failed', ['purpose' => $purpose->value, 'error' => $e->getMessage()]);

            throw new ApiException(ErrorCode::OtpDeliveryFailed, previous: $e);
        }

        Audit::record(AuditEvent::OtpSent, null, ['purpose' => $purpose->value, 'channel' => 'sms']);
    }

    public function resendAfterSeconds(): int
    {
        return (int) config('onehealth.otp.resend_after_seconds');
    }
}
