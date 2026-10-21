<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Auth\CompleteRegistrationRequest;
use App\Http\Requests\Api\V1\Auth\RegistrationIdRequest;
use App\Http\Requests\Api\V1\Auth\SaveEmergencyContactsRequest;
use App\Http\Requests\Api\V1\Auth\StartRegistrationRequest;
use App\Http\Requests\Api\V1\Auth\VerifyRegistrationOtpRequest;
use App\Services\Auth\RegistrationService;
use Illuminate\Http\JsonResponse;

/** Thin on purpose: validate, call the service, wrap the result. The rules live in RegistrationService. */
class RegistrationController extends ApiController
{
    public function __construct(private readonly RegistrationService $registrations) {}

    public function start(StartRegistrationRequest $request): JsonResponse
    {
        $registration = $this->registrations->start(
            $request->validated('phone'),
            $request->validated('nin'),
            (string) $request->ip(),
        )['registration'];

        return $this->ok([
            'registrationId' => $registration->id,
            'identity' => [
                'fullName' => $registration->full_name,
                'dateOfBirth' => $registration->dob->toDateString(),
                'gender' => $registration->gender,
                'photoUrl' => $registration->photo_url,
            ],
        ], 'Identity found.');
    }

    public function confirmIdentity(RegistrationIdRequest $request): JsonResponse
    {
        $seconds = $this->registrations->confirmIdentity($request->validated('registrationId'));

        return $this->ok(['resendAfterSeconds' => $seconds], 'Code sent.');
    }

    public function resendOtp(RegistrationIdRequest $request): JsonResponse
    {
        $seconds = $this->registrations->resendOtp($request->validated('registrationId'));

        return $this->ok(['resendAfterSeconds' => $seconds], 'Code sent.');
    }

    public function verifyOtp(VerifyRegistrationOtpRequest $request): JsonResponse
    {
        $this->registrations->verifyOtp($request->validated('registrationId'), $request->validated('code'));

        return $this->ok(null, 'Phone verified.');
    }

    public function saveContacts(SaveEmergencyContactsRequest $request): JsonResponse
    {
        $this->registrations->saveContacts($request->validated('registrationId'), $request->validated('contacts'));

        return $this->ok(null, 'Emergency contacts saved.');
    }

    public function complete(CompleteRegistrationRequest $request): JsonResponse
    {
        $result = $this->registrations->complete(
            $request->validated('registrationId'),
            $request->validated('password'),
        );

        return $this->created($result, 'Account created.');
    }
}
