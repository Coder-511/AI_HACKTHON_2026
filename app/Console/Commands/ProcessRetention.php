<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Models\ReportAttachment;
use App\Services\SecurityAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessRetention extends Command
{
    protected $signature = 'moshkelti:retention {--apply : Erase expired evidence and anonymize closed reports}';

    protected $description = 'Preview retention by default; apply only the configured expired-data policy';

    public function handle(): int
    {
        $attachments = ReportAttachment::where('expires_at', '<', now());
        $reports = Report::where('closed_at', '<', now()->subDays(config('moshkelti.retention_days')))->whereNull('anonymized_at');
        $this->info('Expired attachments: '.$attachments->count().'; eligible closed reports: '.$reports->count());
        if (! $this->option('apply')) {
            $this->line('Dry run. Use --apply only after approving the retention policy.');

            return self::SUCCESS;
        }
        $attachments->chunkById(100, function ($records): void {
            foreach ($records as $attachment) {
                Storage::disk('private')->delete($attachment->path);
                $attachment->delete();
            }
        });
        $reports->chunkById(100, function ($records): void {
            foreach ($records as $report) {
                DB::transaction(function () use ($report): void {
                    $locked = Report::query()->lockForUpdate()->findOrFail($report->id);
                    if (! $locked->closed_at || $locked->closed_at->greaterThan(now()->subDays(config('moshkelti.retention_days')))) {
                        return;
                    }
                    $locked->title = 'Private content removed under retention policy.';
                    $locked->description = 'Private content removed under retention policy.';
                    $locked->citizen_reason = null;
                    $locked->guidance = null;
                    $locked->latitude = 0;
                    $locked->longitude = 0;
                    $locked->anonymized_at = now();
                    $locked->save();
                    // Retain citizen-safe state history; erase private AI text under the retention policy.
                    DB::table('report_analyses')->where('report_id', $locked->id)->delete();
                    app(SecurityAudit::class)->record('privacy.report_retained_as_summary', null, $locked);
                });
            }
        });
        $this->info('Expired evidence erased and private content minimized. Account linkage is retained for audit; this is not full account erasure.');

        return self::SUCCESS;
    }
}
