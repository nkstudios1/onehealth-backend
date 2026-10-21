<?php

namespace App\Http\Requests\Api\V1\Access;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivateCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cardNumber' => ['required', 'string', 'regex:/^[\d\s-]{16,25}$/'],
        ];
    }
}
