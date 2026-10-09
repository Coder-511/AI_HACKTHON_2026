<?php

namespace Database\Factories;

use App\Models\RegistryCitizen;
use App\Services\NationalIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RegistryCitizen> */
class RegistryCitizenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'national_id' => fake()->unique()->numerify('0000######'),
            'national_id_hash' => fn (array $attributes) => (new NationalIdentity)->fingerprint($attributes['national_id'], true),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'mobile_number' => '+962790000001',
            'governorate' => 'Amman',
            'is_active' => true,
        ];
    }
}
