<?php

namespace Database\Factories;

use App\Models\AuthorityUnit;
use App\Models\DispatchOutbox;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DispatchOutbox> */
class DispatchOutboxFactory extends Factory
{
    public function definition(): array
    {
        return ['report_id' => Report::factory(), 'authority_id' => AuthorityUnit::factory(), 'status' => 'pending'];
    }
}
