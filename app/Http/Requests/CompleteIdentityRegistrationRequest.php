<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CompleteIdentityRegistrationRequest extends FormRequest
{
    protected $redirectRoute = 'register';

    public function authorize(): bool
    {
        return $this->user() === null;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/\\A[0-9]{6}\\z/'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed', 'not_regex:/\\x00/'],
            'password_confirmation' => ['required', 'string', 'max:128'],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['_token', 'code', 'password', 'password_confirmation']) !== []) {
                $validator->errors()->add('registration', 'The registration form contains unexpected fields.');
            }
        }];
    }
}
