<?php

namespace App\Models;

use App\Services\SubmissionAccess;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'mfa_secret', 'mfa_last_counter', 'national_id', 'national_id_hash', 'registry_subject_id', 'identity_verified_at', 'mobile_number', 'mobile_verified_at'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'national_id' => 'encrypted',
            'mobile_number' => 'encrypted',
            'mobile_verified_at' => 'datetime',
            'identity_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_confirmed_at' => 'datetime',
            'mfa_last_counter' => 'integer',
        ];
    }

    public function hasVerifiedCitizenIdentity(): bool
    {
        return $this->national_id_hash !== null && $this->identity_verified_at !== null
            && $this->mobile_number !== null && $this->mobile_verified_at !== null;
    }

    public function restrictions(): HasMany
    {
        return $this->hasMany(UserRestriction::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function submissionAccess(): array
    {
        return (new SubmissionAccess)->details($this);
    }

    public function canSubmitIssues(): bool
    {
        return $this->submissionAccess()['can_submit'];
    }
}
