<?php

namespace App\Services\Auth;

use App\Enums\AuditEvent;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Audit;
use App\Support\Hmac;
use App\Support\Identifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Login and logout for the cookie-session guard.
 *
 * What this protects against, and how:
 *  - Password guessing against ONE account: counted per (identifier + IP) and per identifier
 *    from any IP, so spreading the guesses over many IPs doesn't help.
 *  - Finding out which accounts exist: unknown identifiers cost the same time as known ones
 *    (a dummy hash is checked) and get the same error as a wrong password.
 *  - Session fixation: the session id is replaced on login.
 *  - A suspended account is only revealed AFTER the correct password, so the message can't
 *    be used to discover accounts.
 */
class LoginService
{
    private static ?string $dummyHash = null;

    public function __construct(private readonly AccountFinder $accounts) {}

    public function attempt(string $rawIdentifier, string $password, Request $request): User
    {
        $identifier = Identifier::classify($rawIdentifier);
        $subject = $identifier['value'] ?? mb_strtolower(trim($rawIdentifier));

        $ipKey = 'login:'.Hmac::lookup('login-ip', $subject.'|'.$request->ip());
        $anyKey = 'login-any:'.Hmac::lookup('login-any', $subject);

        $this->guardNotLocked($ipKey, $anyKey, (int) config('onehealth.login.max_attempts'), (int) config('onehealth.login.max_attempts_any_ip'));

        $user = $this->accounts->find($identifier);

        // Always run one password check, so a missing account takes as long as a wrong password.
        $passwordOk = Hash::check($password, $user?->password ?? $this->dummyHash());

        if ($user === null || ! $passwordOk) {
            $decay = (int) config('onehealth.login.decay_minutes') * 60;
            RateLimiter::hit($ipKey, $decay);
            RateLimiter::hit($anyKey, $decay);

            Audit::record(AuditEvent::LoginFailed, $user?->id, ['reason' => 'bad_credentials']);

            throw new ApiException(ErrorCode::InvalidCredentials);
        }

        if (! $user->isActive()) {
            Audit::record(AuditEvent::LoginFailed, $user->id, ['reason' => 'account_'.$user->status->value]);

            throw new ApiException(ErrorCode::AccountSuspended);
        }

        RateLimiter::clear($ipKey);
        RateLimiter::clear($anyKey);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        Audit::record(AuditEvent::LoginSucceeded, $user->id);

        return $user;
    }

    public function logout(Request $request): void
    {
        $userId = Auth::guard('web')->id();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Audit::record(AuditEvent::Logout, is_string($userId) ? $userId : null);
    }

    private function guardNotLocked(string $ipKey, string $anyKey, int $maxIp, int $maxAny): void
    {
        $locked = RateLimiter::tooManyAttempts($ipKey, $maxIp) || RateLimiter::tooManyAttempts($anyKey, $maxAny);

        if (! $locked) {
            return;
        }

        $wait = max(RateLimiter::availableIn($ipKey), RateLimiter::availableIn($anyKey));

        Audit::record(AuditEvent::LoginLocked);

        throw ApiException::tooManyAttempts($wait);
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('not-a-real-password-'.bin2hex(random_bytes(8)));
    }
}
