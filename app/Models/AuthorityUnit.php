<?php

namespace App\Models;

use Database\Factories\AuthorityUnitFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuthorityUnit extends Model
{
    /** @use HasFactory<AuthorityUnitFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['name', 'municipality', 'governorate', 'category', 'active', 'simulated'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'simulated' => 'boolean'];
    }
}
