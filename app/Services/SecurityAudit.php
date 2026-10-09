<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SecurityAudit
{
    public function record(string $event, ?User $actor = null, ?Model $subject = null, array $metadata = []): void
    {
        $safe = array_intersect_key($metadata, array_flip(['state', 'decision', 'type', 'driver', 'result', 'risk_flag', 'status', 'reason_code']));
        AuditLog::create([
            'actor_id' => $actor?->id, 'event' => Str::limit($event, 80, ''),
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject ? (string) $subject->getKey() : null,
            'metadata' => $safe,
            'correlation_id' => app()->bound('correlation_id') ? app('correlation_id') : (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }
}
