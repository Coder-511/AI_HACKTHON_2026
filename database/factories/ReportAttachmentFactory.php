<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\ReportAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportAttachment> */
class ReportAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return ['report_id' => Report::factory(), 'path' => 'quarantine/'.fake()->uuid().'.jpg', 'mime' => 'image/jpeg', 'sha256' => hash('sha256', fake()->uuid()), 'size' => 100, 'scan_status' => 'clean', 'expires_at' => now()->addDays(180)];
    }
}
