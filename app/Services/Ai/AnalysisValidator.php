<?php

namespace App\Services\Ai;

use App\Rules\PlainText;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AnalysisValidator
{
    public function validate(array $result): array
    {
        $expected = ['classification', 'category', 'severity', 'confidence', 'suggested_authority_id', 'missing_information', 'requires_human_review'];
        $keys = array_keys($result);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new \UnexpectedValueException('AI output does not match the approved schema.');
        }
        $validated = Validator::make($result, [
            'classification' => ['required', Rule::in(['public_issue', 'personal_service', 'emergency', 'uncertain'])],
            'category' => ['nullable', Rule::in(array_keys(config('moshkelti.categories')))],
            'severity' => ['required', Rule::in(['low', 'medium', 'high'])],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'suggested_authority_id' => ['nullable', 'uuid'],
            'missing_information' => ['present', 'array', 'max:5'],
            'requires_human_review' => ['required', 'boolean'],
        ])->validate();
        if (! is_bool($result['requires_human_review']) || (! is_float($result['confidence']) && ! is_int($result['confidence'])) || ! array_is_list($result['missing_information'])) {
            throw new \UnexpectedValueException('Invalid AI field types.');
        }
        foreach ($validated['missing_information'] as $question) {
            if (is_string($question)) {
                Validator::make(['question' => $question], ['question' => ['required', 'string', 'max:200', new PlainText]])->validate();
            } else {
                Validator::make(['question' => $question], [
                    'question' => ['required', 'array:ar,en'],
                    'question.ar' => ['required', 'string', 'max:200', 'regex:/[\x{0600}-\x{06FF}]/u', new PlainText],
                    'question.en' => ['required', 'string', 'max:200', 'regex:/[a-zA-Z]/', new PlainText],
                ])->validate();
            }
        }

        return $validated;
    }
}
