<?php

namespace Database\Factories;

use App\Models\Appeal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Appeal> */
class AppealFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'reason' => 'Please reconsider my account restriction.', 'status' => 'pending'];
    }
}
