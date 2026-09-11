<?php

namespace Database\Factories;

use App\Models\Route;
use App\Models\RouteTerminal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteTerminal>
 */
class RouteTerminalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'route_id' => Route::factory(),
            'token' => RouteTerminal::generateToken(),
            'label' => 'Móvil de prueba',
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
