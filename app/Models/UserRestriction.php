<?php

namespace App\Models;

use Database\Factories\UserRestrictionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRestriction extends Model
{
    /** @use HasFactory<UserRestrictionFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'created_by', 'type', 'reason', 'starts_at', 'ends_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
