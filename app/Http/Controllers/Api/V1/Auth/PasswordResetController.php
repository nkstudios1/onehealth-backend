<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Auth\ConfirmPasswordResetRequest;
use App\Http\Requests\Api\V1\Auth\RequestPasswordResetRequest;
use App\Http\Requests\Api\V1\Auth\ResetIdRequest;
use App\Http\Requests\Api\V1\Auth\VerifyResetOtpRequest;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends ApiController
{
    public function __construct(private readonly PasswordResetService $resets) {}

    public function request(RequestPasswordResetRequest $request): JsonResponse
    {
        // Same answer whether or not the account exists. See PasswordResetService.
        return $this->ok(
            $this->resets->request($request->validated('identifier'), (string) $request->ip()),
            'If an account matches, a code is on its way.',
        );
    }

    public function resendOtp(ResetIdRequest $request): JsonResponse
    {
        return $this->ok($this->resets->resendOtp($request->validated('resetId')), 'Code sent.');
    }

    public function verifyOtp(VerifyResetOtpRequest $request): JsonResponse
    {
        return $this->ok(
            $this->resets->verifyOtp($request->validated('resetId'), $request->validated('code')),
            'Code verified.',
        );
    }

    public function confirm(ConfirmPasswordResetRequest $request): JsonResponse
    {
        $this->resets->confirm($request->validated('resetToken'), $request->validated('password'));

        return $this->ok(null, 'Password changed. Log in with your new password.');
    }
}
