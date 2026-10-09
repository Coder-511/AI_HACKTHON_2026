<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RegistryCitizen extends Model
{
    use HasUuids;

    protected $connection = 'registry';

    protected $guarded = ['*'];

    protected $hidden = ['national_id', 'national_id_hash', 'full_name', 'email'];

    protected function casts(): array
    {
        return ['national_id' => 'encrypted', 'full_name' => 'encrypted', 'email' => 'encrypted', 'is_active' => 'boolean'];
    }
}
