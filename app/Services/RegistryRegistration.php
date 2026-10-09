<?php

namespace App\Services;

use App\Contracts\CitizenRegistry;
use App\Models\IdentityRegistrationChallenge;
use App\Models\User;
use App\Notifications\IdentityVerificationCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Throwable;

class RegistryRegistration
{
    public function __construct(private CitizenRegistry $registry, private NationalIdentity $identity, private SecurityAudit $audit) {}

    public function start(#[\SensitiveParameter] string $nationalId, #[\SensitiveParameter] string $mobile, #[\SensitiveParameter] string $sessionSecret): IdentityRegistrationChallenge
    {
        $fingerprint = $this->identity->fingerprint($nationalId);
        $mobileHash = $this->identity->fingerprint('mobile:'.$mobile);
        $citizen = $this->lookup($nationalId);
        if ($citizen && (! hash_equals($mobileHash, $this->identity->fingerprint('mobile:'.$citizen->mobileNumber))
            || User::query()->where('national_id_hash', $fingerprint)->orWhere('registry_subject_id', $citizen->subjectId)->orWhere('email', $citizen->email)->exists())) {
            $citizen = null;
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challenge = new IdentityRegistrationChallenge;
        $challenge->forceFill([
            'registry_subject_id' => $citizen?->subjectId,
            'national_id_hash' => $fingerprint,
            'mobile_hash' => $mobileHash,
            'contact_hash' => $citizen ? $this->contactHash($citizen) : null,
            'session_secret_hash' => hash('sha256', $sessionSecret),
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(5),
        ])->save();
        if ($citizen) {
            try {
                Notification::route('sms', $citizen->mobileNumber)->notify(new IdentityVerificationCode($code));
            } catch (Throwable) {
                $challenge->forceFill(['consumed_at' => now()])->save();
                $this->audit->record('identity.delivery_failed');
            }
        }
        $this->audit->record('identity.challenge_requested');

        return $challenge;
    }

    public function verifyCode(string $challengeId, #[\SensitiveParameter] string $sessionSecret, #[\SensitiveParameter] string $code): bool
    {
        $verified = DB::transaction(function () use ($challengeId, $sessionSecret, $code): bool {
            $challenge = IdentityRegistrationChallenge::query()->lockForUpdate()->find($challengeId);
            if (! $this->usable($challenge, $sessionSecret) || $challenge->verified_at || $challenge->attempts >= 5) {
                return false;
            }
            $challenge->increment('attempts');
            if (! Hash::check($code, $challenge->code_hash) || ! $this->currentCitizen($challenge)) {
                return false;
            }
            $challenge->forceFill(['verified_at' => now(), 'expires_at' => now()->addMinutes(5)])->save();
            $this->audit->record('identity.mobile_verified');

            return true;
        }, 3);
        if (! $verified) {
            $this->audit->record('identity.verification_failed');
        }

        return $verified;
    }

    public function complete(string $challengeId, #[\SensitiveParameter] string $sessionSecret, #[\SensitiveParameter] string $password): ?User
    {
        try {
            return DB::transaction(function () use ($challengeId, $sessionSecret, $password): ?User {
                $challenge = IdentityRegistrationChallenge::query()->lockForUpdate()->find($challengeId);
                if (! $this->usable($challenge, $sessionSecret) || ! $challenge->verified_at) {
                    return null;
                }
                $citizen = $this->currentCitizen($challenge);
                if (! $citizen) {
                    return null;
                }
                $user = new User;
                $user->forceFill([
                    'name' => $citizen->fullName, 'email' => $citizen->email, 'password' => Hash::make($password),
                    'national_id' => $citizen->nationalId, 'national_id_hash' => $challenge->national_id_hash,
                    'registry_subject_id' => $citizen->subjectId, 'identity_verified_at' => now(),
                    'mobile_number' => $citizen->mobileNumber, 'mobile_verified_at' => $challenge->verified_at,
                    'is_admin' => false,
                ])->save();
                $challenge->forceFill(['consumed_at' => now()])->save();
                $this->audit->record('auth.registered', $user, $user);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->audit->record('identity.duplicate_prevented');

            return null;
        }
    }

    public function stage(string $challengeId, #[\SensitiveParameter] string $sessionSecret): string
    {
        $challenge = IdentityRegistrationChallenge::query()->find($challengeId);
        if (! $this->usable($challenge, $sessionSecret)) {
            return 'identity';
        }

        return $challenge->verified_at ? 'password' : 'code';
    }

    private function usable(?IdentityRegistrationChallenge $challenge, #[\SensitiveParameter] string $sessionSecret): bool
    {
        return $challenge !== null && hash_equals($challenge->session_secret_hash, hash('sha256', $sessionSecret))
            && ! $challenge->consumed_at && $challenge->expires_at->greaterThan(now());
    }

    private function currentCitizen(IdentityRegistrationChallenge $challenge): ?CitizenIdentity
    {
        if (! $challenge->registry_subject_id) {
            return null;
        }
        try {
            $citizen = $this->registry->find($challenge->registry_subject_id);
        } catch (Throwable) {
            $this->audit->record('identity.registry_unavailable');

            return null;
        }
        if (! $citizen || ! hash_equals($challenge->national_id_hash, $this->identity->fingerprint($citizen->nationalId))
            || ! hash_equals((string) $challenge->mobile_hash, $this->identity->fingerprint('mobile:'.$citizen->mobileNumber))
            || ! hash_equals((string) $challenge->contact_hash, $this->contactHash($citizen))
            || User::query()->where('national_id_hash', $challenge->national_id_hash)->orWhere('registry_subject_id', $citizen->subjectId)->orWhere('email', $citizen->email)->exists()) {
            $challenge->forceFill(['consumed_at' => now()])->save();

            return null;
        }

        return $citizen;
    }

    private function contactHash(CitizenIdentity $citizen): string
    {
        return $this->identity->fingerprint('contact:'.$citizen->mobileNumber.'|'.$citizen->email);
    }

    private function lookup(#[\SensitiveParameter] string $nationalId): ?CitizenIdentity
    {
        try {
            return $this->registry->lookup($nationalId);
        } catch (Throwable) {
            $this->audit->record('identity.registry_unavailable');

            return null;
        }
    }
}
