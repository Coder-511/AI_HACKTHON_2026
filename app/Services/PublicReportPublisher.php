<?php

namespace App\Services;

use App\Models\Report;
use Illuminate\Validation\ValidationException;

class PublicReportPublisher
{
    public function publish(Report $report, string $title, string $summary): void
    {
        if (preg_match('/@|https?:\/\/|\+?\d[\d\s().-]{6,}\d/u', $title.' '.$summary)) {
            throw ValidationException::withMessages(['public_summary' => 'Public summaries must not contain contact details, long numbers or external links.']);
        }
        $report->public_title = $title;
        $report->public_summary = $summary;
        $report->published_at = now();
        $report->save();
    }
}
