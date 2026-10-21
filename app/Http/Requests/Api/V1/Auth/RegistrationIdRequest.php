<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** Used by every sign-up step that only needs to say which sign-up it is. */
class RegistrationIdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['registrationId' => ['required', 'string', 'max:64']];
    }
}
