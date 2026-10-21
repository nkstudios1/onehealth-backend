<?php

namespace App\Services\Auth;

use App\Contracts\SmsGateway;
use App\Enums\AuditEvent;
use App\Enums\ErrorCode;
use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Mail\PasswordResetCodeMail;
use App\Models\PasswordReset;
use App\Models\User;
use App\Support\Audit;
use App\Support\Hmac;
use App\Support\Identifier;
use App\Support\Mask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Forgot-password flow:  request -> (resendOtp) -> verifyOtp -> confirm
 *
 * The rule that shapes everything here: the response to `request` must NOT reveal whether
 * an account exists. So:
 *  - an unknown identifier gets a normal-looking reset row and the same response shape;
 *  - the SMS/email is sent AFTER the response is delivered, so a real account doesn't
 *    respond slower than a fake one;
 *  - verifying a code on a fake reset always says "wrong code".
 */
class PasswordResetService
{
    public function __construct(
        private readonly AccountFinder $accounts,
        private readonly OtpService $otp,
        private readonly SmsGateway $sms,
    ) {}

    /** @return array{resetId: string, channel: string, destination: string, resendAfterSeconds: int} */
    public function request(string $rawIdentifier, string $ip): array
    {
        $identifier = Identifier::classify($rawIdentifier);
        $subject = $identifier['value'] ?? mb_strtolower(trim($rawIdentifier));
        $identifierHash = Hmac::lookup('reset-identifier', $subject);

        // Same cap for real and fake identifiers, so hitting it reveals nothing.
        $limitKey = 'reset-request:'.$identifierHash;
        if (RateLimiter::tooManyAttempts($limitKey, (int) config('onehealth.password_reset.max_requests_per_hour'))) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($limitKey));
        }
        RateLimiter::hit($limitKey, 3600);

        $user = $this->accounts->find($identifier);

        [$channel, $destinationRaw] = $this->channelFor($user, $identifier, $identifierHash);

        $reset = PasswordReset::create([
            'user_id' => $user?->id,
            'channel' => $channel,
            'identifier_hash' => $identifierHash,
            'destination' => Mask::destination($destinationRaw),
            'otp_sent_at' => now(),
            'expires_at' => now()->addMinutes((int) config('onehealth.password_reset.ttl_minutes')),
            'ip_address' => $ip,
        ]);

        Audit::record(AuditEvent::PasswordResetRequested, $user?->id, ['reset_id' => $reset->id, 'channel' => $channel]);

        if ($user !== null) {
            $this->sendCodeAfterResponse($reset, $destinationRaw);
        }

        return [
            'resetId' => $reset->id,
            'channel' => $channel,
            'destination' => $reset->destination,
            'resendAfterSeconds' => $this->otp->resendAfterSeconds(),
        ];
    }

    /** @return array{resendAfterSeconds: int} */
    public function resendOtp(string $resetId): array
    {
        $reset = $this->findUsable($resetId);

        if ($reset->otp_verified_at !== null) {
            throw new ApiException(ErrorCode::ResetNotFound);
        }

        $wait = (int) config('onehealth.otp.resend_after_seconds');
        $elapsed = $reset->otp_sent_at === null ? $wait : (int) $reset->otp_sent_at->diffInSeconds(now(), true);

        if ($elapsed < $wait) {
            throw ApiException::tooManyAttempts($wait - $elapsed);
        }

        $reset->update(['otp_sent_at' => now()]);

        if ($reset->user_id !== null) {
            $user = User::find($reset->user_id);
            if ($user !== null) {
                $this->sendCodeAfterResponse($reset, $reset->channel === 'email' ? (string) $user->email : (string) $user->phone);
            }
        }

        return ['resendAfterSeconds' => $this->otp->resendAfterSeconds()];
    }

    /** @return array{resetToken: string} */
    public function verifyOtp(string $resetId, string $code): array
    {
        $reset = $this->findUsable($resetId);

        if ($reset->user_id === null) {
            // A fake reset (no such account). It must behave like a wrong code, never "not found".
            Audit::record(AuditEvent::OtpFailed, null, ['purpose' => OtpPurpose::PasswordReset->value, 'reason' => 'no_account']);

            throw new ApiException(ErrorCode::OtpInvalid);
        }

        $this->otp->verify(OtpPurpose::PasswordReset, $reset->id, $code);

        $token = Str::random(64);

        $reset->update([
            'otp_verified_at' => now(),
            'token_hash' => Hmac::lookup('reset-token', $token),
            'token_expires_at' => now()->addMinutes((int) config('onehealth.password_reset.token_ttl_minutes')),
        ]);

        Audit::record(AuditEvent::PasswordResetVerified, $reset->user_id, ['reset_id' => $reset->id]);

        return ['resetToken' => $token];
    }

    /** Set the new password. Signs the person out everywhere and tells them it happened. */
    public function confirm(string $token, string $password): void
    {
        $tokenHash = Hmac::lookup('reset-token', $token);

        $user = DB::transaction(function () use ($tokenHash, $password): User {
            $reset = PasswordReset::query()
                ->where('token_hash', $tokenHash)
                ->whereNull('used_at')
                ->where('token_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            $user = $reset?->user_id ? User::find($reset->user_id) : null;

            if ($reset === null || $user === null) {
                throw new ApiException(ErrorCode::ResetNotFound);
            }

            $user->password = $password;
            $user->save();

            $reset->update(['used_at' => now(), 'token_hash' => null]);

            // Anyone logged in with the old password (maybe the person who stole it) is signed out.
            DB::table('sessions')->where('user_id', $user->id)->delete();

            return $user;
        });

        Audit::record(AuditEvent::PasswordResetCompleted, $user->id);

        if (config('onehealth.password_reset.notify_on_change')) {
            $this->notifyChanged($user);
        }
    }

    /**
     * Which channel gets the code, and where. Real accounts: the way the person typed
     * (email -> email, otherwise their phone). Fake ones get a believable made-up destination.
     *
     * @param  array{type: string, value: string}|null  $identifier
     * @return array{0: string, 1: string}
     */
    private function channelFor(?User $user, ?array $identifier, string $identifierHash): array
    {
        $typedEmail = ($identifier['type'] ?? null) === Identifier::EMAIL;

        if ($user !== null) {
            if ($typedEmail && $user->email) {
                return ['email', $user->email];
            }
            if ($user->phone) {
                return ['sms', $user->phone];
            }
            if ($user->email) {
                return ['email', $user->email];
            }
        }

        // Unknown account: invent a destination that looks like the real thing but is stable
        // for the same input, so asking twice doesn't give two different answers.
        if ($typedEmail) {
            return ['email', 'user'.substr($identifierHash, 0, 4).'@'.substr($identifierHash, 4, 6).'.com'];
        }

        $digits = preg_replace('/\D/', '', substr(hash('sha256', $identifierHash), 0, 24)) ?? '';
        $digits = str_pad($digits, 8, '0');

        return ['sms', '080'.substr($digits, 0, 8)];
    }

    /** Send the code once the response has gone out. Failures are logged, never shown (that would leak too). */
    private function sendCodeAfterResponse(PasswordReset $reset, string $destination): void
    {
        $this->afterResponse(function () use ($reset, $destination) {
            try {
                if ($reset->channel === 'sms') {
                    $this->otp->sendBySms(OtpPurpose::PasswordReset, $reset->id, $destination);

                    return;
                }

                $code = $this->otp->issue(OtpPurpose::PasswordReset, $reset->id);
                $minutes = (int) ceil(((int) config('onehealth.otp.ttl_seconds')) / 60);
                Mail::to($destination)->send(new PasswordResetCodeMail($code, $minutes));
            } catch (Throwable $e) {
                Log::error('Password reset code delivery failed', ['reset_id' => $reset->id, 'error' => $e->getMessage()]);
            }
        });
    }

    private function notifyChanged(User $user): void
    {
        $this->afterResponse(function () use ($user) {
            try {
                $text = "Your OneHealth password was just changed. If this wasn't you, contact support right away.";

                if ($user->phone) {
                    $this->sms->send($user->phone, $text);
                } elseif ($user->email) {
                    Mail::raw($text, fn ($m) => $m->to($user->email)->subject('Your OneHealth password was changed'));
                }
            } catch (Throwable $e) {
                Log::error('Password change notice failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Run something once the response has been sent. Guarded to run only once: terminating
     * callbacks can be re-run when one application instance serves several requests (tests, Octane).
     */
    private function afterResponse(callable $callback): void
    {
        $ran = false;

        app()->terminating(function () use ($callback, &$ran) {
            if ($ran) {
                return;
            }
            $ran = true;
            $callback();
        });
    }

    private function findUsable(string $resetId): PasswordReset
    {
        $reset = Str::isUuid($resetId) ? PasswordReset::find($resetId) : null;

        if ($reset === null || ! $reset->isUsable()) {
            throw new ApiException(ErrorCode::ResetNotFound);
        }

        return $reset;
    }
}
