<?php

namespace App\Jobs;

use App\Models\DispatchOutbox;
use App\Models\Report;
use App\Services\AuthorityDispatcher;
use App\Services\ReportWorkflow;
use App\Services\SecurityAudit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DispatchReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 15;

    public function __construct(public string $outboxId) {}

    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function handle(AuthorityDispatcher $dispatcher): void
    {
        $dispatcher->deliver($this->outboxId);
    }

    public function failed(?\Throwable $exception): void
    {
        DB::transaction(function (): void {
            $outbox = DispatchOutbox::query()->lockForUpdate()->find($this->outboxId);
            if (! $outbox || $outbox->status !== 'pending') {
                return;
            }
            $report = Report::query()->lockForUpdate()->find($outbox->report_id);
            if (! $report || $report->status !== 'routed' || $report->authority_id !== $outbox->authority_id) {
                return;
            }
            app()->instance('correlation_id', $report->correlation_id);
            $outbox->status = 'failed';
            $outbox->save();
            app(ReportWorkflow::class)->transition($report, 'dispatch_failed', 'Pilot delivery could not finish. An administrator must review the delivery failure.');
            app(SecurityAudit::class)->record('dispatch.job_failed', null, $report, ['reason_code' => 'terminal_failure']);
        });
    }
}
