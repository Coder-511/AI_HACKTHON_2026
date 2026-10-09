<?php

namespace App\Http\Requests;

use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class RestrictUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $endsAt = $this->input('ends_at');
        if (is_string($endsAt) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $endsAt)) {
            try {
                $this->merge(['ends_at' => Carbon::parse($endsAt, 'Asia/Amman')->utc()->toIso8601String()]);
            } catch (\Throwable) {
                // Keep invalid input for normal date validation.
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['type' => ['required', Rule::in(['warning', 'temporary_suspend', 'banned', 'reversal'])],
            'risk_flag' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'reason' => ['required', 'string', 'min:10', 'max:2000', new PlainText],
            'ends_at' => ['required_if:type,temporary_suspend', 'nullable', 'date', 'after:now', 'before:'.now()->addYear()->toIso8601String()]];
    }
}
