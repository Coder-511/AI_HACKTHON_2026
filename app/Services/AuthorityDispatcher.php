<?php

namespace App\Services;

use App\Jobs\DispatchReport;
use App\Models\DispatchOutbox;
use App\Models\Report;
use Illuminate\Support\Facades\DB;

class AuthorityDispatcher
{
    public function enqueue(Report $report): void
    {
        abort_unless($report->status === 'accepted' && $report->authority?->active && $report->authority->simulated, 409, 'A reviewed pilot authority is required.');
        $outbox = DispatchOutbox::firstOrCreate(['report_id' => $report->id], ['authority_id' => $report->authority_id, 'status' => 'pending']);
        $report = app(ReportWorkflow::class)->transition($report, 'routed', 'The approved report was queued for simulated pilot delivery.');
        DispatchReport::dispatch($outbox->id)->afterCommit();
    }

    public function deliver(string $outboxId): void
    {
        DB::transaction(function () use ($outboxId): void {
            $outbox = DispatchOutbox::query()->lockForUpdate()->find($outboxId);
            if (! $outbox || $outbox->status === 'sent') {
                return;
            }
            $report = Report::query()->lockForUpdate()->findOrFail($outbox->report_id);
            if ($report->status !== 'routed' || $report->authority_id !== $outbox->authority_id) {
                return;
            }
            abort_unless($outbox->authority->active && $outbox->authority->simulated, 409);
            // Deliberately no HTTP/email government calls. One outbox row yields one deterministic simulated receipt.
            $outbox->status = 'sent';
            $outbox->attempts++;
            $outbox->receipt = 'SIM-'.$outbox->id;
            $outbox->sent_at = now();
            $outbox->save();
            app(SecurityAudit::class)->record('dispatch.simulated', null, $report, ['result' => 'sent']);
            app(ReportWorkflow::class)->transition($report, 'acknowledged', 'The simulated pilot authority acknowledged this report. No government request was sent.');
        });
    }
}
