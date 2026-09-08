<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'driver_id' => null,
            'label' => 'Tracker '.fake()->unique()->bothify('??##'),
            'platform' => 'android',
            'install_identifier' => fake()->unique()->uuid(),
            'app_version' => '1.'.fake()->numberBetween(0, 9).'.'.fake()->numberBetween(0, 9),
            'last_seen_at' => fake()->dateTimeBetween('-2 days', 'now'),
            'is_active' => true,
        ];
    }

    public function forDriver(Driver $driver): static
    {
        return $this->state(['driver_id' => $driver->id]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
