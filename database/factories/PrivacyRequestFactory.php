<?php

namespace Database\Factories;

use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrivacyRequest> */
class PrivacyRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'type' => 'access', 'details' => 'Please provide my personal information.', 'status' => 'pending'];
    }
}
