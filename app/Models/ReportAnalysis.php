<?php

namespace App\Models;

use Database\Factories\ReportAnalysisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportAnalysis extends Model
{
    /** @use HasFactory<ReportAnalysisFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['report_id', 'report_version', 'driver', 'result', 'validation_state', 'created_at'];

    protected function casts(): array
    {
        return ['result' => 'encrypted:array', 'created_at' => 'datetime'];
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
