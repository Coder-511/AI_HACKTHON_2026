<?php

namespace App\Http\Requests;

use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrivacyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['type' => ['required', Rule::in(['access', 'correction', 'erase', 'withdraw'])], 'details' => ['nullable', 'string', 'max:2000', new PlainText]];
    }
}
