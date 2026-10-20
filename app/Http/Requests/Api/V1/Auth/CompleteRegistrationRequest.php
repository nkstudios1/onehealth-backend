<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class CompleteRegistrationRequest extends FormRequest
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
            'password' => ['required', 'string', 'max:'.config('onehealth.password.max_length'), Password::defaults()],
        ];
    }
}
