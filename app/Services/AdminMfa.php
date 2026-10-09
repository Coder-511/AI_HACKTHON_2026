<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AdminMfa
{
    private const Alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $buffer = 0;
        $bits = 0;
        $secret = '';

        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $secret .= self::Alphabet[($buffer >> $bits) & 31];
            }

            $buffer &= (1 << $bits) - 1;
        }

        return $secret;
    }

    public function provisioningUri(User $user, #[\SensitiveParameter] string $secret): string
    {
        return 'otpauth://totp/'.rawurlencode('Moshkelti:'.$user->email).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => 'Moshkelti',
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function enable(User $user, #[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code): bool
    {
        return DB::transaction(function () use ($user, $secret, $code): bool {
            $administrator = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $administrator || ! $administrator->is_admin || $administrator->mfa_confirmed_at !== null) {
                return false;
            }

            $counter = $this->matchingCounter($secret, $code);

            if ($counter === null) {
                return false;
            }

            $administrator->forceFill([
                'mfa_secret' => $secret,
                'mfa_confirmed_at' => now(),
                'mfa_last_counter' => $counter,
            ]);

            return User::query()->whereKey($administrator->getKey())
                ->where('is_admin', true)
                ->whereNull('mfa_confirmed_at')
                ->update(Arr::only($administrator->getAttributes(), ['mfa_secret', 'mfa_confirmed_at', 'mfa_last_counter'])) === 1;
        }, 3);
    }

    public function verify(User $user, #[\SensitiveParameter] string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $administrator = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $administrator || ! $administrator->is_admin || $administrator->mfa_confirmed_at === null || ! is_string($administrator->mfa_secret)) {
                return false;
            }

            $counter = $this->matchingCounter($administrator->mfa_secret, $code);

            if ($counter === null || ($administrator->mfa_last_counter !== null && $counter <= (int) $administrator->mfa_last_counter)) {
                return false;
            }

            return User::query()->whereKey($administrator->getKey())
                ->where('is_admin', true)
                ->whereNotNull('mfa_confirmed_at')
                ->where('mfa_last_counter', $administrator->mfa_last_counter)
                ->update(['mfa_last_counter' => $counter]) === 1;
        }, 3);
    }

    private function matchingCounter(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code): ?int
    {
        if (! preg_match('/\A[A-Z2-7]{32}\z/', $secret) || ! preg_match('/\A[0-9]{6}\z/', $code)) {
            return null;
        }

        $key = $this->decodeSecret($secret);
        $currentCounter = intdiv(now()->getTimestamp(), 30);

        for ($counter = $currentCounter + 1; $counter >= max(0, $currentCounter - 1); $counter--) {
            $message = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
            $hash = hash_hmac('sha1', $message, $key, true);
            $offset = ord($hash[19]) & 15;
            $number = unpack('Nvalue', substr($hash, $offset, 4))['value'] & 0x7FFFFFFF;
            $expected = str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);

            if (hash_equals($expected, $code)) {
                return $counter;
            }
        }

        return null;
    }

    private function decodeSecret(#[\SensitiveParameter] string $secret): string
    {
        $buffer = 0;
        $bits = 0;
        $bytes = '';

        foreach (str_split($secret) as $character) {
            $buffer = ($buffer << 5) | strpos(self::Alphabet, $character);
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $bytes .= chr(($buffer >> $bits) & 255);
            }

            $buffer &= (1 << $bits) - 1;
        }

        return $bytes;
    }
}
