<?php

namespace App\Models;

use Database\Factories\RegistryCitizenFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegistryCitizen extends Model
{
    /** @use HasFactory<RegistryCitizenFactory> */
    use HasFactory, HasUuids;

    protected $connection = 'registry';

    protected $guarded = ['*'];

    protected $hidden = ['national_id', 'national_id_hash', 'full_name', 'email', 'mobile_number'];

    protected function casts(): array
    {
        return ['national_id' => 'encrypted', 'full_name' => 'encrypted', 'email' => 'encrypted', 'mobile_number' => 'encrypted', 'is_active' => 'boolean'];
    }
}
