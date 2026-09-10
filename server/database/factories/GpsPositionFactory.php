<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\GpsPosition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GpsPosition>
 *
 * En producción y en la mayoría de tests las posiciones se insertan en bloque
 * (`GpsPosition::insert(...)`, como hace `GpsIngestService`). Esta factory es para los tests
 * que prefieren construirlas de una en una.
 */
class GpsPositionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'latitude' => fake()->latitude(28.0, 28.6),
            'longitude' => fake()->longitude(-16.9, -16.1),
            'accuracy_m' => fake()->randomFloat(1, 3, 25),
            'speed_mps' => fake()->randomFloat(1, 0, 20),
            'recorded_at' => fake()->dateTimeBetween('-1 hour', 'now'),
        ];
    }
}
