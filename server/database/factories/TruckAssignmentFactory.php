<?php

namespace Database\Factories;

use App\Models\Driver;
use App\Models\Truck;
use App\Models\TruckAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TruckAssignment>
 */
class TruckAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'truck_id' => Truck::factory(),
            'driver_id' => Driver::factory(),
            'valid_from' => today()->subMonth(),
            'valid_until' => null,
        ];
    }
}
