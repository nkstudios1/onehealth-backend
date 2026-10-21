<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;

class StartRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => Phone::normalise($this->input('phone'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:'.Phone::PATTERN],
            'nin' => ['required', 'string', 'regex:/^\d{11}$/'],
            // Consent must be an explicit yes. It is stored with the version of the text agreed to.
            'consent' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid 11-digit phone number.',
            'nin.regex' => 'Enter your 11-digit NIN.',
            'consent.accepted' => 'You must accept the terms to continue.',
        ];
    }
}
