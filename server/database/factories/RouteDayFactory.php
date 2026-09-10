<?php

namespace Database\Factories;

use App\Enums\RouteStatus;
use App\Enums\ServiceKind;
use App\Models\Driver;
use App\Models\Route;
use App\Models\RouteDay;
use App\Models\Truck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteDay>
 */
class RouteDayFactory extends Factory
{
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-1 week', '+1 week');

        return [
            'code' => 'R-'.fake()->unique()->numerify('########'),
            'route_date' => $date,
            'route_id' => Route::factory(),
            'truck_id' => fn (array $attrs) => Route::find($attrs['route_id'])?->truck_id ?? Truck::factory()->create()->id,
            'driver_id' => fn (array $attrs) => Route::find($attrs['route_id'])?->driver_id ?? Driver::factory()->create()->id,
            'status' => RouteStatus::Published,
            'service_kind' => ServiceKind::Reparto->value,
            'name' => 'Ruta de prueba',
        ];
    }

    public function status(RouteStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function trip(): static
    {
        return $this->state(fn () => ['service_kind' => ServiceKind::Viaje->value]);
    }
}
