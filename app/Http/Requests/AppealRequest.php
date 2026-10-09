<?php

namespace App\Http\Requests;

use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;

class AppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:10', 'max:2000', new PlainText]];
    }
}
