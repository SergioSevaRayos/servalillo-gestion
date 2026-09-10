<?php

use App\Models\Driver;
use App\Models\Route;
use App\Models\Truck;
use App\Models\TruckAssignment;
use App\Models\User;

it('genera las rutas de una fecha concreta pasada por argumento', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    TruckAssignment::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $driver->id,
        'valid_from' => today()->subMonth(),
    ]);

    $fecha = today()->addDays(3)->toDateString();

    $this->artisan("rutas:generar-rutas {$fecha}")
        ->expectsOutputToContain('1 rutas creadas')
        ->assertExitCode(0);

    expect(Route::where('truck_id', $truck->id)->whereDate('route_date', $fecha)->exists())->toBeTrue();
});

it('sin fecha genera el horizonte de hoy a +14 días', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    TruckAssignment::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $driver->id,
        'valid_from' => today()->subMonth(),
    ]);

    $this->artisan('rutas:generar-rutas')
        ->expectsOutputToContain('15 rutas creadas')
        ->assertExitCode(0);

    expect(Route::where('truck_id', $truck->id)->count())->toBe(15);
});
