<?php

use App\Enums\RouteStatus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Truck;
use App\Models\TruckAssignment;
use App\Models\User;
use App\Services\RecurringRouteService;

it('genera una ruta publicada con el código y el chofer de la asignación vigente', function () {
    $truck = Truck::factory()->create(['code' => 'C-09']);
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    TruckAssignment::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $driver->id,
        'valid_from' => today()->subMonth(),
        'valid_until' => null,
    ]);

    $created = app(RecurringRouteService::class)->generateForDate(today());

    expect($created)->toBe(1);

    $route = Route::where('truck_id', $truck->id)->whereDate('route_date', today())->first();
    expect($route)->not->toBeNull()
        ->and($route->driver_id)->toBe($driver->id)
        ->and($route->status)->toBe(RouteStatus::Published)
        ->and($route->code)->toBe('R-'.today()->format('Ymd').'-C-09');
});

it('es idempotente: repetir generateForDate no duplica la ruta', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    TruckAssignment::factory()->create(['truck_id' => $truck->id, 'driver_id' => $driver->id]);

    $service = app(RecurringRouteService::class);
    $service->generateForDate(today());
    $second = $service->generateForDate(today());

    expect($second)->toBe(0)
        ->and(Route::where('truck_id', $truck->id)->count())->toBe(1);
});

it('no genera ruta antes de valid_from ni después de valid_until', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    TruckAssignment::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $driver->id,
        'valid_from' => today()->addDays(2),
        'valid_until' => today()->addDays(5),
    ]);

    $service = app(RecurringRouteService::class);

    expect($service->generateForDate(today()->addDay()))->toBe(0)
        ->and($service->generateForDate(today()->addDays(6)))->toBe(0)
        ->and($service->generateForDate(today()->addDays(3)))->toBe(1);
});

it('no genera rutas para fechas pasadas', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    TruckAssignment::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $driver->id,
        'valid_from' => today()->subMonth(),
    ]);

    $created = app(RecurringRouteService::class)->generateForDate(today()->subDay());

    expect($created)->toBe(0)
        ->and(Route::where('truck_id', $truck->id)->exists())->toBeFalse();
});

it('no pisa una ruta ya creada a mano para ese camión y esa fecha', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    $otroChofer = Driver::factory()->for(User::factory(), 'user')->create();

    $manual = Route::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $otroChofer->id,
        'route_date' => today(),
    ]);

    TruckAssignment::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $driver->id,
        'valid_from' => today()->subMonth(),
    ]);

    $created = app(RecurringRouteService::class)->generateForDate(today());

    expect($created)->toBe(0)
        ->and($manual->fresh()->driver_id)->toBe($otroChofer->id);
});

it('generateHorizon(14) cubre desde hoy hasta +14 días', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    TruckAssignment::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $driver->id,
        'valid_from' => today()->subMonth(),
    ]);

    $created = app(RecurringRouteService::class)->generateHorizon(14);

    expect($created)->toBe(15) // hoy + 14 días siguientes
        ->and(Route::where('truck_id', $truck->id)->whereDate('route_date', today()->addDays(14))->exists())->toBeTrue()
        ->and(Route::where('truck_id', $truck->id)->whereDate('route_date', today()->addDays(15))->exists())->toBeFalse();
});
