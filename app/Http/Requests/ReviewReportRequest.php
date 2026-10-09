<?php

namespace App\Http\Requests;

use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['accept', 'reject', 'needs_more_information', 'resolve', 'reopen'])],
            'reason' => ['required', 'string', 'min:10', 'max:2000', new PlainText],
            'version' => ['required', 'integer', 'min:1'], 'authority_id' => ['nullable', 'uuid'],
            'governorate' => ['nullable', Rule::in(config('moshkelti.governorates'))],
            'category' => ['nullable', Rule::in(array_keys(config('moshkelti.categories')))],
            'publish' => ['sometimes', 'boolean'],
            'public_title' => ['required_if:publish,1', 'nullable', 'string', 'max:160', new PlainText],
            'public_summary' => ['required_if:publish,1', 'nullable', 'string', 'max:1500', new PlainText],
        ];
    }
}
