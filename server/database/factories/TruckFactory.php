<?php

namespace Database\Factories;

use App\Models\Truck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Truck>
 */
class TruckFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 99);

        return [
            'plate' => sprintf('%04d-%s', fake()->numberBetween(1000, 9999), fake()->bothify('???')),
            'code' => sprintf('C-%02d', $n),
            'description' => 'Cisterna '.fake()->randomElement(['gasóleo', 'agua', 'mixta']),
            'capacity_liters' => fake()->randomElement([10000, 13000, 16000, 20000]),
            'compartments' => fake()->numberBetween(1, 4),
            'model' => fake()->randomElement(['Volvo FH', 'Scania R', 'MAN TGX', 'Iveco S-Way']),
            'year' => fake()->numberBetween(2015, 2025),
            'odometer' => fake()->numberBetween(50000, 400000),
            'is_active' => true,
        ];
    }
}
