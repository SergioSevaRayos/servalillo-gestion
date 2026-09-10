<?php

namespace Database\Factories;

use App\Models\RouteStop;
use App\Models\StopVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StopVisit>
 */
class StopVisitFactory extends Factory
{
    public function definition(): array
    {
        $enteredAt = fake()->dateTimeBetween('-3 hours', '-1 hour');
        $seconds = fake()->numberBetween(180, 2400);

        return [
            'route_stop_id' => RouteStop::factory(),
            'route_id' => fn (array $attrs) => RouteStop::find($attrs['route_stop_id'])?->route_id,
            'entered_at' => $enteredAt,
            'left_at' => (clone $enteredAt)->modify("+{$seconds} seconds"),
            'seconds' => $seconds,
        ];
    }

    /** Visita abierta: el camión sigue dentro del radio. */
    public function open(): static
    {
        return $this->state(fn () => ['left_at' => null, 'seconds' => null]);
    }
}
