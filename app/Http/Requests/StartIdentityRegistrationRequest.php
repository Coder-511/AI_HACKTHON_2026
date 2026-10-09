<?php

namespace App\Http\Requests;

use App\Services\NationalIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StartIdentityRegistrationRequest extends FormRequest
{
    protected $redirectRoute = 'register';

    public function authorize(): bool
    {
        return $this->user() === null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('national_id'))) {
            $this->merge(['national_id' => (new NationalIdentity)->normalize($this->input('national_id'))]);
        }
        if (is_string($this->input('mobile_number'))) {
            $this->merge(['mobile_number' => (new NationalIdentity)->normalizeMobile($this->input('mobile_number'))]);
        }
    }

    public function rules(): array
    {
        return [
            'national_id' => ['required', 'string', 'regex:/\\A[0-9]{10}\\z/'],
            'mobile_number' => ['required', 'string', 'regex:/\\A\\+9627[0-9]{8}\\z/'],
            'registry_consent' => ['required', 'accepted'],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['_token', 'national_id', 'mobile_number', 'registry_consent']) !== []) {
                $validator->errors()->add('registration', 'The registration form contains unexpected fields.');
            }
        }];
    }
}
