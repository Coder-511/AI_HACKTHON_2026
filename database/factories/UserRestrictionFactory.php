<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserRestriction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserRestriction> */
class UserRestrictionFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'type' => 'temporary_suspend', 'reason' => 'Repeated spam submissions under review.',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()];
    }
}
