<?php

namespace App\Http\Requests;

use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;

class AdditionalInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['description' => ['required', 'string', 'min:20', 'max:5000', new PlainText],
            'version' => ['required', 'integer', 'min:1'], 'status' => ['prohibited'], 'user_id' => ['prohibited'], 'authority_id' => ['prohibited']];
    }
}
