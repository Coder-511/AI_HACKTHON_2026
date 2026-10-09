<?php

namespace Database\Factories;

use App\Models\AuthorityUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuthorityUnit> */
class AuthorityUnitFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Simulated roads department', 'municipality' => config('moshkelti.municipality'), 'governorate' => 'Amman', 'category' => 'roads', 'active' => true, 'simulated' => true];
    }
}
