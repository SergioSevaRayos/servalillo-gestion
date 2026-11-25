<?php

use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Livewire\Chofer\Today;
use App\Models\Client;
use App\Models\Driver;
use App\Models\Route;
use App\Models\RouteDay;
use App\Models\RouteStop;
use App\Models\Truck;
use Livewire\Livewire;

/*
| "Capturar ubicación" del chofer (docs/18): además de la parada de hoy y la ficha del cliente,
| rellena las paradas del cliente ya planificadas para los próximos días.
*/

/** Chofer con su ruta de hoy y una de la semana que viene; devuelve [user, paradaHoy, paradaFutura]. */
function choferWithFutureStop(array $stopData): array
{
    $user = makeUser('chofer');
    $driver = Driver::factory()->create(['user_id' => $user->id]);
    $truck = Truck::factory()->create();
    $permanent = Route::factory()->create([
        'driver_id' => $driver->id, 'truck_id' => $truck->id,
        'valid_from' => today()->subMonth(), 'valid_until' => null,
    ]);

    $stops = [];
    foreach ([today(), today()->addWeek()] as $date) {
        $day = RouteDay::factory()->create([
            'route_id' => $permanent->id, 'driver_id' => $driver->id, 'truck_id' => $truck->id,
            'route_date' => $date, 'status' => RouteStatus::Published,
        ]);
        $stops[] = RouteStop::factory()->for($day, 'route')->create([
            'status' => RouteStopStatus::Pending, 'position' => 1,
            'latitude' => null, 'longitude' => null, ...$stopData,
        ]);
    }

    return [$user, ...$stops];
}

it('capturar la ubicación rellena también las paradas futuras ya planificadas del cliente', function () {
    [$user, $today, $future] = choferWithFutureStop(['customer_tax_id' => 'B11111111', 'customer_name' => 'Granja Sol']);
    Client::factory()->create(['tax_id' => 'B11111111', 'latitude' => null, 'longitude' => null]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('captureStopCoordinates', $today->id, 38.2, -0.7)
        ->assertDispatched('toast');

    expect((float) $future->fresh()->latitude)->toBe(38.2)
        ->and((float) $future->fresh()->longitude)->toBe(-0.7);
});

it('no toca las paradas futuras si el cliente ya tenía ubicación', function () {
    [$user, $today, $future] = choferWithFutureStop(['customer_tax_id' => 'B22222222', 'customer_name' => 'Finca Luna']);
    Client::factory()->create(['tax_id' => 'B22222222', 'latitude' => 38.9, 'longitude' => -0.9]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('captureStopCoordinates', $today->id, 38.2, -0.7);

    expect($future->fresh()->latitude)->toBeNull()
        ->and((float) $today->fresh()->latitude)->toBe(38.2);
});

it('no pisa una parada futura que ya tenía su propia ubicación', function () {
    [$user, $today, $future] = choferWithFutureStop(['customer_tax_id' => 'B33333333', 'customer_name' => 'Casa Mar']);
    $future->update(['latitude' => 37.5, 'longitude' => -1.5]);
    Client::factory()->create(['tax_id' => 'B33333333', 'latitude' => null, 'longitude' => null]);

    Livewire::actingAs($user)->test(Today::class)
        ->call('captureStopCoordinates', $today->id, 38.2, -0.7);

    expect((float) $future->fresh()->latitude)->toBe(37.5);
});
