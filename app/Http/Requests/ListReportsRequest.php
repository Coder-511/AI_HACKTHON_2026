<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['sort' => ['sometimes', Rule::in(['created_at', 'severity', 'status'])],
            'per_page' => ['sometimes', 'integer', 'between:1,50'], 'category' => ['nullable', Rule::in(array_keys(config('moshkelti.categories')))]];
    }
}
