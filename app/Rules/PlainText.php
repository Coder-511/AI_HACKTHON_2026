<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PlainText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)
            || preg_match('/<\s*\/?\s*[a-z!][^>]*>/iu', $value)
            || preg_match('/(?:javascript|data|file|ftp|vbscript)\s*:/iu', $value)) {
            $fail('Use plain text without HTML, control characters or executable links.');
        }
    }
}
