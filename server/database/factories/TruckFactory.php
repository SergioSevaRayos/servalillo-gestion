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
        // Rango amplio (no solo 1-99): varios tests fijan un código concreto a mano (p. ej.
        // 'C-99' en FleetStatsServiceTest/TrucksCrudTest) vía ->create(['code' => 'C-99']) — eso
        // NO se lo comunica a fake()->unique(), que sigue repartiendo números de este rango sin
        // saber que 99 ya está "reservado". Con solo 99 valores posibles, un camión random
        // generado de refilón (p. ej. el que crea RouteDay::factory() -> Route::factory() al no
        // indicar route_id) tenía ~1% de probabilidad de chocar con ese código fijo por test — real
        // en producción: rompió un despliegue (violación de trucks_code_unique). Con 4 dígitos el
        // choque es prácticamente cero sin tocar el formato de los tests existentes.
        $n = fake()->unique()->numberBetween(1, 9999);

        return [
            'plate' => sprintf('%04d-%s', fake()->numberBetween(1000, 9999), fake()->bothify('???')),
            'code' => sprintf('C-%02d', $n),
            'description' => 'Cisterna '.fake()->randomElement(['gasóleo', 'agua', 'mixta']),
            'capacity_liters' => fake()->randomElement([10000, 13000, 16000, 20000]),
            'compartments' => fake()->numberBetween(1, 4),
            'model' => fake()->randomElement(['Volvo FH', 'Scania R', 'MAN TGX', 'Iveco S-Way']),
            'year' => fake()->numberBetween(2015, 2025),
            'odometer' => fake()->numberBetween(50000, 400000),
            'liter_meter' => fake()->numberBetween(100000, 900000),
            'is_active' => true,
        ];
    }
}
