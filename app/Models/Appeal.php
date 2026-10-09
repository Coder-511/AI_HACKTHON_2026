<?php

namespace App\Models;

use Database\Factories\AppealFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appeal extends Model
{
    /** @use HasFactory<AppealFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['user_id', 'report_id', 'reason', 'status'];

    protected function casts(): array
    {
        return ['reason' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
