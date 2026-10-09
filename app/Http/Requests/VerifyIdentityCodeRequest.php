<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class VerifyIdentityCodeRequest extends FormRequest
{
    protected $redirectRoute = 'register';

    public function authorize(): bool
    {
        return $this->user() === null;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'regex:/\\A[0-9]{6}\\z/']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['_token', 'code']) !== []) {
                $validator->errors()->add('registration', 'The verification form contains unexpected fields.');
            }
        }];
    }
}
