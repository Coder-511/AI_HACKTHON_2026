<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\ReportEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ReportEvent> */
class ReportEventFactory extends Factory
{
    public function definition(): array
    {
        return ['report_id' => Report::factory(), 'from_status' => 'processing', 'to_status' => 'needs_review', 'message' => 'Awaiting human review.', 'correlation_id' => (string) Str::uuid(), 'created_at' => now()];
    }
}
