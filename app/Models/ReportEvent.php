<?php

namespace App\Models;

use Database\Factories\ReportEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportEvent extends Model
{
    /** @use HasFactory<ReportEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['report_id', 'actor_id', 'from_status', 'to_status', 'message', 'correlation_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Append-only record.'));
        static::deleting(fn () => throw new \LogicException('Append-only record.'));
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
