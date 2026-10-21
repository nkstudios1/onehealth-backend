<?php

namespace App\Http\Requests\Api\V1\Access;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSessionRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(['clinical_report', 'vitals', 'medical_history'])],
            'type' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:10000'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
