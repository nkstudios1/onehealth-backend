<?php

use App\Http\Controllers\Api\V1\Auth\CsrfController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use Illuminate\Support\Facades\Route;

/*
| Patient authentication.  URLs: /api/v1/auth/...
| The throttle names are defined in AppServiceProvider::configureRateLimiting().
*/
Route::prefix('auth')->name('auth.')->group(function () {

    // Sets the XSRF-TOKEN cookie. The app calls this once before its first POST.
    Route::get('csrf', CsrfController::class)->name('csrf');

    Route::prefix('register')->name('register.')->group(function () {
        Route::post('start', [RegistrationController::class, 'start'])->middleware('throttle:register-start')->name('start');
        Route::post('confirm-identity', [RegistrationController::class, 'confirmIdentity'])->middleware('throttle:otp-send')->name('confirm-identity');
        Route::post('resend-otp', [RegistrationController::class, 'resendOtp'])->middleware('throttle:otp-send')->name('resend-otp');
        Route::post('verify-otp', [RegistrationController::class, 'verifyOtp'])->middleware('throttle:otp-verify')->name('verify-otp');
        Route::post('emergency-contacts', [RegistrationController::class, 'saveContacts'])->middleware('throttle:auth-step')->name('emergency-contacts');
        Route::post('complete', [RegistrationController::class, 'complete'])->middleware('throttle:auth-step')->name('complete');
    });

    Route::post('login', [LoginController::class, 'login'])->middleware('throttle:login')->name('login');

    Route::middleware(['auth:web', 'active'])->group(function () {
        Route::get('me', [LoginController::class, 'me'])->name('me');
        Route::post('logout', [LoginController::class, 'logout'])->name('logout');
    });

    Route::prefix('password-reset')->name('password-reset.')->group(function () {
        Route::post('request', [PasswordResetController::class, 'request'])->middleware('throttle:otp-send')->name('request');
        Route::post('resend-otp', [PasswordResetController::class, 'resendOtp'])->middleware('throttle:otp-send')->name('resend-otp');
        Route::post('verify-otp', [PasswordResetController::class, 'verifyOtp'])->middleware('throttle:otp-verify')->name('verify-otp');
        Route::post('confirm', [PasswordResetController::class, 'confirm'])->middleware('throttle:auth-step')->name('confirm');
    });
});
