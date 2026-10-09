<?php

namespace App\Models;

use Database\Factories\ReportAttachmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportAttachment extends Model
{
    /** @use HasFactory<ReportAttachmentFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['report_id', 'path', 'mime', 'sha256', 'size', 'scan_status', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function isSafeForDownload(): bool
    {
        return ($this->scan_status === 'clean' || ($this->scan_status === 'local_sanitized' && ! app()->isProduction()))
            && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
