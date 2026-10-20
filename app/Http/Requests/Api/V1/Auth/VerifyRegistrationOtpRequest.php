<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyRegistrationOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'registrationId' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'digits:'.config('onehealth.otp.length')],
        ];
    }
}
