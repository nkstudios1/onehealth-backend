<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;

class SaveEmergencyContactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $contacts = $this->input('contacts');

        if (is_array($contacts)) {
            $this->merge(['contacts' => array_map(function ($c) {
                if (is_array($c) && isset($c['phone']) && is_string($c['phone'])) {
                    $c['phone'] = Phone::normalise($c['phone']);
                }
                if (is_array($c) && array_key_exists('email', $c) && $c['email'] === '') {
                    $c['email'] = null;
                }

                return $c;
            }, $contacts)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'registrationId' => ['required', 'string', 'max:64'],
            'contacts' => ['required', 'array', 'min:1', 'max:3'],
            'contacts.*.name' => ['required', 'string', 'max:200'],
            // Free text on purpose: the app only suggests Parent, Sibling... people can type their own.
            'contacts.*.relationship' => ['required', 'string', 'max:100'],
            'contacts.*.phone' => ['required', 'string', 'regex:'.Phone::PATTERN, 'distinct'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
