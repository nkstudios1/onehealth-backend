<?php

namespace App\Http\Requests\Api\V1\Access;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScanCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'qrToken' => ['required_without:cardNumber', 'nullable', 'string', 'max:64'],
            'cardNumber' => ['required_without:qrToken', 'nullable', 'string', 'regex:/^[\d\s-]{16,25}$/'],
            // Needed with a typed card number, so a number someone saw is not enough by itself.
            'dateOfBirth' => ['required_with:cardNumber', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
