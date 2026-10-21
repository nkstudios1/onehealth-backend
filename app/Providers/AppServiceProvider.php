<?php

namespace App\Providers;

use App\Contracts\NinVerifier;
use App\Contracts\SmsGateway;
use App\Services\Nin\FakeNinVerifier;
use App\Services\Sms\LogSmsGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ---- Swappable outside services -------------------------------------------------
        // To add a real NIN vendor or SMS provider: write a class implementing the interface,
        // add a line to the match below, set NIN_DRIVER / SMS_DRIVER in .env. Nothing else changes.
        $this->app->singleton(NinVerifier::class, function () {
            $driver = (string) config('onehealth.nin.driver');

            if ($driver === 'fake' && $this->app->isProduction()) {
                throw new RuntimeException('NIN_DRIVER=fake is not allowed in production. Configure a real NIN verifier.');
            }

            return match ($driver) {
                'fake' => new FakeNinVerifier,
                default => throw new RuntimeException("Unknown NIN_DRIVER [{$driver}]."),
            };
        });

        $this->app->singleton(SmsGateway::class, function () {
            $driver = (string) config('onehealth.sms.driver');

            if ($driver === 'log' && $this->app->isProduction()) {
                throw new RuntimeException('SMS_DRIVER=log is not allowed in production (it writes codes to the log). Configure a real SMS gateway.');
            }

            return match ($driver) {
                'log' => new LogSmsGateway,
                default => throw new RuntimeException("Unknown SMS_DRIVER [{$driver}]."),
            };
        });
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    private function configureDefaults(): void
    {
        // In production a destructive command (migrate:fresh, db:wipe) must never run by accident.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // While developing, turn silent mistakes into loud errors: writing to a column that
        // isn't fillable, or reading one that wasn't loaded. (Off in production: never crash a patient.)
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());

        // The password rule used everywhere (sign-up and reset): same as the patient app, plus a maximum.
        Password::defaults(function () {
            $rule = Password::min((int) config('onehealth.password.min_length'))->letters()->numbers();

            return config('onehealth.password.check_breaches') ? $rule->uncompromised() : $rule;
        });
    }

    /**
     * Per-IP speed limits on top of the per-account limits inside the services. A 429 from
     * here comes out in the standard error shape with a Retry-After header.
     */
    private function configureRateLimiting(): void
    {
        $byIp = fn (Request $r) => (string) $r->ip();

        // Starting a sign-up costs us a NIN lookup (money), so it is the tightest.
        RateLimiter::for('register-start', fn (Request $r) => Limit::perMinute(5)->by($byIp($r)));

        // Anything that sends an SMS or email. Per IP, plus per sign-up/reset so one flow can't be hammered.
        RateLimiter::for('otp-send', fn (Request $r) => [
            Limit::perMinute(10)->by($byIp($r)),
            Limit::perMinute(3)->by($byIp($r).'|'.(string) ($r->input('registrationId') ?? $r->input('resetId') ?? $r->input('identifier'))),
        ]);

        // Guessing codes. The code itself is also killed after N wrong tries (OtpService).
        RateLimiter::for('otp-verify', fn (Request $r) => [
            Limit::perMinute(10)->by($byIp($r)),
            Limit::perMinute(6)->by($byIp($r).'|'.(string) ($r->input('registrationId') ?? $r->input('resetId'))),
        ]);

        // Other sign-up / reset steps.
        RateLimiter::for('auth-step', fn (Request $r) => Limit::perMinute(20)->by($byIp($r)));

        // Login has stricter per-account counting in LoginService; this stops one IP spraying many accounts.
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(20)->by($byIp($r)));
    }
}
