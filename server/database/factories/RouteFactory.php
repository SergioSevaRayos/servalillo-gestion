<?php

namespace Database\Factories;

use App\Enums\ServiceKind;
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
        return [
            'truck_id' => Truck::factory(),
            'driver_id' => Driver::factory(),
            'service_kind' => ServiceKind::Reparto->value,
            'valid_from' => fake()->dateTimeBetween('-6 months', '-1 week'),
            'valid_until' => null,
            'name' => 'Ruta de prueba',
        ];
    }

    public function trip(): static
    {
        return $this->state(fn () => ['service_kind' => ServiceKind::Viaje->value]);
    }
}
