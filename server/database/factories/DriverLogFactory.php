<?php

namespace Database\Factories;

use App\Enums\DriverLogCategory;
use App\Models\Driver;
use App\Models\DriverLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverLog>
 */
class DriverLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'occurred_on' => now()->toDateString(),
            'category' => DriverLogCategory::Neutral->value,
            'body' => fake()->sentence(),
        ];
    }
}
