<?php

namespace App\Http\Requests\Api\V1\Access;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:'.config('onehealth.access.code_length')],
        ];
    }
}
