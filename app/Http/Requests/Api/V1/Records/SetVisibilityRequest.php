<?php

namespace App\Http\Requests\Api\V1\Records;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'visibility' => ['required', Rule::in(['public', 'private'])],
        ];
    }
}
