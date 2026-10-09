<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'tracking_code' => 'MSK-'.strtoupper(Str::random(12)),
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', fake()->uuid()),
            'title' => 'Damaged road near a public park', 'description' => 'There is a large pothole in the public road causing a safety concern.',
            'governorate' => 'Amman', 'latitude' => 31.95, 'longitude' => 35.91, 'category' => 'roads',
            'severity' => 'low', 'status' => 'needs_review', 'consented_at' => now(), 'version' => 1,
            'correlation_id' => (string) Str::uuid()];
    }
}
