<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IdentityRegistrationChallenge extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['registry_subject_id', 'national_id_hash', 'contact_hash', 'session_secret_hash', 'code_hash'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
