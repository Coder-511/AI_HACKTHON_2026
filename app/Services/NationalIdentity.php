<?php

namespace App\Services;

use LogicException;

class NationalIdentity
{
    public function normalize(#[\SensitiveParameter] string $nationalId): string
    {
        return strtr(trim($nationalId), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    public function normalizeMobile(#[\SensitiveParameter] string $mobile): string
    {
        $mobile = preg_replace('/[ ()-]/', '', $this->normalize($mobile));
        if (str_starts_with($mobile, '07')) {
            return '+962'.substr($mobile, 1);
        }
        if (str_starts_with($mobile, '00962')) {
            return '+'.substr($mobile, 2);
        }

        return $mobile;
    }

    public function fingerprint(#[\SensitiveParameter] string $value, bool $registry = false): string
    {
        $key = config($registry ? 'identity.registry_lookup_key' : 'identity.lookup_key');
        if (! is_string($key) || strlen($key) < 32) {
            throw new LogicException('An identity lookup key of at least 32 characters must be configured.');
        }

        return hash_hmac('sha256', $value, $key);
    }
}
