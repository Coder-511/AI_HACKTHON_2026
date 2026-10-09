<?php

namespace App\Models;

use Database\Factories\DispatchOutboxFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchOutbox extends Model
{
    /** @use HasFactory<DispatchOutboxFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['report_id', 'authority_id', 'status', 'attempts', 'receipt', 'sent_at'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'sent_at' => 'datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function authority(): BelongsTo
    {
        return $this->belongsTo(AuthorityUnit::class, 'authority_id');
    }
}
