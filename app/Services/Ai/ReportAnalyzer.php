<?php

namespace App\Services\Ai;

use App\Models\AuthorityUnit;
use App\Models\Report;
use App\Services\SecurityAudit;
use Illuminate\Support\Facades\Cache;

class ReportAnalyzer
{
    public function __construct(private LocalAnalyzer $local, private RemoteAnalyzer $remote, private AnalysisValidator $validator) {}

    public function analyze(Report $report): array
    {
        $report->refresh();
        if (preg_match('/ignore.{0,40}(instructions|rules)|system\s*prompt|تجاهل.{0,30}(تعليمات|قواعد)/iu', $report->description)) {
            app(SecurityAudit::class)->record('ai.possible_prompt_injection', null, $report, ['reason_code' => 'untrusted_instructions']);
        }
        $context = ['title' => $this->redact($report->title), 'description' => $this->redact($report->description),
            'governorate' => $report->governorate, 'category' => $report->category];
        $driver = 'local';
        $result = $this->local->analyze($context);
        // A remote recommendation cannot override deterministic emergency/service guidance.
        if (config('moshkelti.ai_driver') === 'remote' && $report->external_ai_consent
            && ! in_array($result['classification'], ['emergency', 'personal_service'], true)) {
            $allowed = Cache::lock('moshkelti-ai-budget-lock', 10)->block(3, function (): bool {
                $key = 'moshkelti-ai-cost:'.now()->toDateString();
                $count = (int) Cache::get($key, 0);
                if ($count >= config('moshkelti.ai_daily_budget')) {
                    return false;
                }
                Cache::put($key, $count + 1, now()->endOfDay());

                return true;
            });
            if ($allowed) {
                $driver = 'remote';
                $result = $this->remote->analyze($context);
            } else {
                app(SecurityAudit::class)->record('ai.budget_exhausted', null, $report);
            }
        }

        $validated = $this->validator->validate($result);
        if ($validated['suggested_authority_id'] !== null) {
            $approved = AuthorityUnit::whereKey($validated['suggested_authority_id'])->where('active', true)
                ->where('municipality', config('moshkelti.municipality'))
                ->where('governorate', $report->resolved_governorate ?? $report->governorate)
                ->where('category', $validated['category'])->exists();
            if (! $approved) {
                throw new \UnexpectedValueException('AI suggested an unapproved authority.');
            }
        }

        return ['driver' => $driver, 'result' => $validated];
    }

    private function redact(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email removed]', $text);
        $text = preg_replace('/(?:https?:\/\/|www\.)\S+/iu', '[link removed]', $text);

        return preg_replace('/\+?[\d٠-٩][\d٠-٩\s().-]{6,}[\d٠-٩]/u', '[number removed]', $text);
    }
}
