<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\ReportAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportAnalysis> */
class ReportAnalysisFactory extends Factory
{
    public function definition(): array
    {
        return ['report_id' => Report::factory(), 'report_version' => 1, 'driver' => 'local', 'result' => [], 'validation_state' => 'validated', 'created_at' => now()];
    }
}
