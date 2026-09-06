<?php

namespace Database\Factories;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'employee_code' => 'EMP-'.fake()->unique()->numberBetween(100, 999),
            'license_number' => strtoupper(fake()->bothify('????######')),
            'license_expiry' => fake()->dateTimeBetween('+3 months', '+4 years'),
            'phone' => fake()->phoneNumber(),
            'is_active' => true,
        ];
    }
}
