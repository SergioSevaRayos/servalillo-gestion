<?php

namespace Database\Factories;

use App\Enums\RouteStopStatus;
use App\Models\Route;
use App\Models\RouteStop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteStop>
 */
class RouteStopFactory extends Factory
{
    public function definition(): array
    {
        return [
            'route_id' => Route::factory(),
            'position' => fake()->numberBetween(1, 20),
            'customer_name' => fake()->company(),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(28.0, 28.6),
            'longitude' => fake()->longitude(-16.9, -16.1),
            'status' => RouteStopStatus::Pending,
            'planned_quantity' => fake()->numberBetween(100, 2000),
            'data' => [],
        ];
    }
}
