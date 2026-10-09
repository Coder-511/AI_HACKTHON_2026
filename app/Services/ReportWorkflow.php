<?php

namespace App\Services;

use App\Models\Report;
use App\Models\ReportEvent;
use App\Models\User;
use App\Notifications\ReportStatusChanged;
use Illuminate\Support\Facades\DB;

class ReportWorkflow
{
    public const REVIEW_DECISIONS = ['accept' => 'accepted', 'reject' => 'rejected', 'needs_more_information' => 'needs_more_information', 'resolve' => 'resolved', 'reopen' => 'needs_review'];

    private const TRANSITIONS = [
        'received' => ['processing'],
        'processing' => ['needs_review', 'needs_more_information', 'emergency_guidance', 'service_guidance', 'duplicate'],
        'needs_review' => ['accepted', 'rejected', 'needs_more_information', 'emergency_guidance', 'service_guidance'],
        'needs_more_information' => ['processing', 'rejected', 'accepted'],
        'accepted' => ['routed', 'rejected'],
        'routed' => ['acknowledged', 'dispatch_failed', 'resolved'],
        'dispatch_failed' => ['routed'],
        'acknowledged' => ['in_progress', 'resolved'],
        'in_progress' => ['resolved'],
        'resolved' => ['needs_review', 'closed'],
        'rejected' => ['needs_review'],
        'duplicate' => ['needs_review'],
        'emergency_guidance' => ['needs_review'],
        'service_guidance' => ['needs_review'],
    ];

    public function allowedReviewDecisions(Report $report): array
    {
        return array_keys(array_filter(self::REVIEW_DECISIONS,
            fn (string $status, string $decision): bool => in_array($status, self::TRANSITIONS[$report->status] ?? [], true)
                && ($decision !== 'reopen' || in_array($report->status, ['resolved', 'rejected', 'duplicate', 'emergency_guidance', 'service_guidance'], true)), ARRAY_FILTER_USE_BOTH));
    }

    public function transition(Report $report, string $status, string $message, ?User $actor = null): Report
    {
        return DB::transaction(function () use ($report, $status, $message, $actor): Report {
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);
            if (! in_array($status, self::TRANSITIONS[$locked->status] ?? [], true)) {
                throw new \DomainException('Invalid report status transition.');
            }
            $previous = $locked->status;
            $locked->status = $status;
            $locked->version++;
            if (in_array($status, ['resolved', 'rejected', 'closed', 'duplicate', 'service_guidance', 'emergency_guidance'], true)) {
                $locked->closed_at = now();
            } else {
                $locked->closed_at = null;
            }
            $locked->save();
            ReportEvent::create(['report_id' => $locked->id, 'actor_id' => $actor?->id, 'from_status' => $previous,
                'to_status' => $status, 'message' => $message, 'correlation_id' => $locked->correlation_id, 'created_at' => now()]);
            app(SecurityAudit::class)->record('report.transition', $actor, $locked, ['status' => $status]);
            $locked->user->notify((new ReportStatusChanged($locked->id, $status))->afterCommit());

            return $locked;
        });
    }
}
