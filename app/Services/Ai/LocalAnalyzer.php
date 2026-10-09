<?php

namespace App\Services\Ai;

use App\Contracts\IssueAnalyzer;

class LocalAnalyzer implements IssueAnalyzer
{
    public function analyze(array $context): array
    {
        $text = mb_strtolower(($context['title'] ?? '').' '.($context['description'] ?? ''));
        $classification = 'uncertain';
        $severity = 'low';
        $category = null;
        if (preg_match('/حريق|انهيار|تسرب غاز|خطر على الحياة|fire|gas leak|life.threat|collapse/u', $text)) {
            $classification = 'emergency';
            $severity = 'high';
        } elseif (preg_match('/جواز سفري|تجديد جواز|بطاقتي|رخصتي|passport|my licence|my license|identity card/u', $text)) {
            $classification = 'personal_service';
        } else {
            $terms = [
                'roads' => ['/حفرة|حفر|pothole/u' => 3, '/طريق|شارع|road|street/u' => 1],
                'lighting' => ['/إنارة|انارة|مصباح|streetlight|lighting/u' => 3],
                'waste' => ['/نفايات|قمامة|زبالة|waste|garbage|rubbish/u' => 3],
                'drainage' => ['/صرف|مجاري|فيضان|drain|sewage|flood/u' => 3],
                'public_facilities' => ['/رصيف|مرفق|sidewalk|public facility/u' => 3, '/حديقة|park/u' => 1],
            ];
            $bestScore = 0;
            foreach ($terms as $candidate => $patterns) {
                $score = 0;
                foreach ($patterns as $pattern => $weight) {
                    if (preg_match($pattern, $text)) {
                        $score += $weight;
                    }
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $category = $candidate;
                    $classification = 'public_issue';
                }
            }
        }
        $injection = (bool) preg_match('/ignore.{0,40}(instructions|rules)|system\s*prompt|execute.{0,20}(sql|command)|تجاهل.{0,30}(تعليمات|قواعد)/iu', $text);

        return [
            'classification' => $classification, 'category' => $category, 'severity' => $severity,
            'confidence' => $classification === 'uncertain' || $injection ? 0.2 : 0.65,
            'suggested_authority_id' => null, 'missing_information' => [],
            'requires_human_review' => true,
        ];
    }
}
