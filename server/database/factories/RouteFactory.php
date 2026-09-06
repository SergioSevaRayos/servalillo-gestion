<?php

namespace Database\Factories;

use App\Enums\RouteStatus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Truck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Route>
 */
class RouteFactory extends Factory
{
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-1 week', '+1 week');

        return [
            'code' => 'R-'.fake()->unique()->numerify('########'),
            'route_date' => $date,
            'truck_id' => Truck::factory(),
            'driver_id' => Driver::factory(),
            'status' => RouteStatus::Published,
            'name' => 'Ruta de prueba',
        ];
    }

    public function status(RouteStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
