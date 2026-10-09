<?php

namespace App\Jobs;

use App\Models\Report;
use App\Models\ReportAnalysis;
use App\Services\Ai\ReportAnalyzer;
use App\Services\ReportWorkflow;
use App\Services\SecurityAudit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class AnalyzeReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 40;

    public function __construct(public string $reportId, public int $version) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('analysis:'.$this->reportId))->expireAfter(90)->releaseAfter(10)];
    }

    public function handle(ReportAnalyzer $analyzer, ReportWorkflow $workflow): void
    {
        $report = Report::find($this->reportId);
        if (! $report || $report->status !== 'processing' || $report->version !== $this->version) {
            return;
        }
        app()->instance('correlation_id', $report->correlation_id);
        try {
            $analysis = $analyzer->analyze($report);
        } catch (\Throwable) {
            $analysis = ['driver' => config('moshkelti.ai_driver'), 'result' => [
                'classification' => 'uncertain', 'category' => null, 'severity' => 'low', 'confidence' => 0.0,
                'suggested_authority_id' => null, 'missing_information' => [], 'requires_human_review' => true,
            ]];
            app(SecurityAudit::class)->record('ai.invalid_or_unavailable', null, $report, ['reason_code' => 'review_fallback']);
        }
        DB::transaction(function () use ($analysis, $workflow): void {
            $report = Report::query()->lockForUpdate()->find($this->reportId);
            if (! $report || $report->status !== 'processing' || $report->version !== $this->version) {
                return;
            }
            if (ReportAnalysis::where('report_id', $report->id)->where('report_version', $this->version)->exists()) {
                return;
            }
            ReportAnalysis::create(['report_id' => $report->id, 'report_version' => $this->version,
                'driver' => $analysis['driver'], 'result' => $analysis['result'], 'validation_state' => 'validated', 'created_at' => now()]);
            $result = $analysis['result'];
            $report->category = $result['category'] ?? $report->category;
            $report->severity = $result['severity'];
            // AI-proposed authorities and governorates never become authoritative.
            $report->save();
            if ($result['classification'] === 'emergency') {
                $report->guidance = 'للخطر الفوري في الأردن اتصل 911. تذكر مديرية الأمن العام 114 للتواصل بلغة الإشارة. هذا التطبيق ليس خدمة طوارئ ولا يرسل مستجيبين. / For immediate danger in Jordan, call 911. PSD lists 114 for sign-language communication. This application is not an emergency service and does not dispatch responders.';
                $report->save();
                $workflow->transition($report, 'emergency_guidance', 'Emergency guidance is available; this app does not provide emergency response.');

                return;
            }
            if ($result['classification'] === 'personal_service') {
                $report->guidance = 'يبدو أنه طلب خدمة شخصية. راجع بوابة الخدمات الحكومية الرسمية أو اطلب من المراجع تحديد الإجراء الصحيح. لم نفترض مستندات أو مواعيد. / This appears to be a personal service request. Check the official government service portal or ask a reviewer for the correct procedure. No documents or deadlines have been inferred.';
                $report->save();
                $workflow->transition($report, 'service_guidance', 'Your request needs service guidance, not an infrastructure complaint.');

                return;
            }
            $duplicate = $this->findDuplicate($report);
            if ($duplicate) {
                $report->duplicate_of = $duplicate->id;
                $report->save();
                $workflow->transition($report, 'duplicate', 'A reviewer will associate this report with an existing case; private case details are not shared.');

                return;
            }
            $workflow->transition($report, 'needs_review', 'Your report is awaiting a human review of location, jurisdiction and evidence.');
        });
    }

    private function findDuplicate(Report $report): ?Report
    {
        if (! $report->category) {
            return null;
        }
        $normalize = fn (string $text): string => preg_replace('/\s+/u', ' ', mb_strtolower(trim($text)));
        $candidates = Report::whereKeyNot($report->id)->where('category', $report->category)
            ->where('governorate', $report->governorate)->whereIn('status', ['accepted', 'routed', 'acknowledged', 'in_progress'])
            ->where('created_at', '>=', now()->subDays(30))
            ->whereBetween('latitude', [(float) $report->latitude - 0.001, (float) $report->latitude + 0.001])
            ->whereBetween('longitude', [(float) $report->longitude - 0.001, (float) $report->longitude + 0.001])
            ->latest()->limit(100)->get();

        return $candidates->first(fn (Report $candidate) => $normalize($candidate->description) === $normalize($report->description));
    }
}
