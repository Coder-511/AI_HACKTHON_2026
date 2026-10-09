<?php

namespace App\Http\Requests;

use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:160', new PlainText],
            'description' => ['required', 'string', 'min:20', 'max:5000', new PlainText],
            'governorate' => ['required', Rule::in(config('moshkelti.governorates'))],
            'category' => ['nullable', Rule::in(array_keys(config('moshkelti.categories')))],
            'latitude' => ['required', 'numeric', 'between:29,33.5'],
            'longitude' => ['required', 'numeric', 'between:34.5,39.5'],
            'consent' => ['accepted'],
            'external_ai_consent' => ['sometimes', 'boolean'],
            'idempotency_key' => ['required', 'uuid'],
            'attachments' => ['sometimes', 'array', 'max:3'],
            'attachments.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096'],
            'user_id' => ['prohibited'], 'status' => ['prohibited'], 'authority_id' => ['prohibited'],
            'public_title' => ['prohibited'], 'published_at' => ['prohibited'],
            'redirect' => ['prohibited'], 'return_url' => ['prohibited'], 'next' => ['prohibited'],
        ];
    }
}
