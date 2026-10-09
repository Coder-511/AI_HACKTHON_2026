<?php

namespace App\Models;

use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['user_id', 'tracking_code', 'idempotency_key', 'payload_hash', 'title', 'description', 'governorate', 'resolved_governorate', 'latitude', 'longitude', 'category', 'severity', 'status', 'citizen_reason', 'guidance', 'external_ai_consent', 'consented_at', 'version', 'authority_id', 'duplicate_of', 'public_title', 'public_summary', 'published_at', 'closed_at', 'anonymized_at', 'correlation_id'];

    protected function casts(): array
    {
        return ['title' => 'encrypted', 'description' => 'encrypted', 'citizen_reason' => 'encrypted', 'guidance' => 'encrypted', 'latitude' => 'decimal:6', 'longitude' => 'decimal:6', 'external_ai_consent' => 'boolean', 'consented_at' => 'datetime', 'published_at' => 'datetime', 'closed_at' => 'datetime', 'anonymized_at' => 'datetime', 'version' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function authority(): BelongsTo
    {
        return $this->belongsTo(AuthorityUnit::class, 'authority_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ReportAttachment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReportEvent::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(ReportAnalysis::class);
    }
}
