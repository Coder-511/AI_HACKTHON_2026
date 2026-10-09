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

    public function start(#[\SensitiveParameter] string $nationalId, #[\SensitiveParameter] string $sessionSecret): IdentityRegistrationChallenge
    {
        $fingerprint = $this->identity->fingerprint($nationalId);
        $citizen = $this->lookup($nationalId);
        if ($citizen && User::query()->where('national_id_hash', $fingerprint)->orWhere('registry_subject_id', $citizen->subjectId)
            ->orWhere('email', $citizen->email)->exists()) {
            $citizen = null;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challenge = new IdentityRegistrationChallenge;
        $challenge->forceFill([
            'registry_subject_id' => $citizen?->subjectId,
            'national_id_hash' => $fingerprint,
            'contact_hash' => $citizen ? $this->identity->fingerprint('contact:'.$citizen->email) : null,
            'session_secret_hash' => hash('sha256', $sessionSecret),
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(5),
        ])->save();

        if ($citizen) {
            try {
                Notification::route('mail', $citizen->email)->notify(new IdentityVerificationCode($code));
            } catch (Throwable) {
                $challenge->forceFill(['consumed_at' => now()])->save();
                $this->audit->record('identity.delivery_failed');
            }
        }
        $this->audit->record('identity.challenge_requested');

        return $challenge;
    }

    public function complete(string $challengeId, #[\SensitiveParameter] string $sessionSecret, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $password): ?User
    {
        try {
            $user = DB::transaction(function () use ($challengeId, $sessionSecret, $code, $password): ?User {
                $challenge = IdentityRegistrationChallenge::query()->lockForUpdate()->find($challengeId);
                if (! $challenge || ! hash_equals($challenge->session_secret_hash, hash('sha256', $sessionSecret))
                    || $challenge->consumed_at || $challenge->expires_at->lessThanOrEqualTo(now()) || $challenge->attempts >= 5) {
                    return null;
                }

                $challenge->increment('attempts');
                if (! Hash::check($code, $challenge->code_hash) || ! $challenge->registry_subject_id) {
                    return null;
                }

                try {
                    $citizen = $this->registry->find($challenge->registry_subject_id);
                } catch (Throwable) {
                    $this->audit->record('identity.registry_unavailable');

                    return null;
                }

                if (! $citizen || ! hash_equals($challenge->national_id_hash, $this->identity->fingerprint($citizen->nationalId))
                    || ! hash_equals((string) $challenge->contact_hash, $this->identity->fingerprint('contact:'.$citizen->email))
                    || User::query()->where('national_id_hash', $challenge->national_id_hash)
                        ->orWhere('registry_subject_id', $citizen->subjectId)->orWhere('email', $citizen->email)->exists()) {
                    $challenge->forceFill(['consumed_at' => now()])->save();

                    return null;
                }

                $user = new User;
                $user->forceFill([
                    'name' => $citizen->fullName, 'email' => $citizen->email, 'password' => Hash::make($password),
                    'national_id' => $citizen->nationalId, 'national_id_hash' => $challenge->national_id_hash,
                    'registry_subject_id' => $citizen->subjectId, 'identity_verified_at' => now(),
                    'email_verified_at' => now(), 'is_admin' => false,
                ])->save();
                $challenge->forceFill(['consumed_at' => now()])->save();
                $this->audit->record('auth.registered', $user, $user);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $user = null;
        }

        if (! $user) {
            $this->audit->record('identity.verification_failed');
        }

        return $user;
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
