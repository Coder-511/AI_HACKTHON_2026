<?php

namespace App\Models;

use Database\Factories\PrivacyRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrivacyRequest extends Model
{
    /** @use HasFactory<PrivacyRequestFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['user_id', 'type', 'details', 'status'];

    protected function casts(): array
    {
        return ['details' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
