<?php

use App\Enums\RouteStatus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\RouteDay;
use App\Models\Truck;
use App\Models\User;
use App\Services\RecurringRouteService;

it('genera un RouteDay publicado con el código y el chofer de la ruta vigente', function () {
    $truck = Truck::factory()->create(['code' => 'C-09']);
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    Route::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $driver->id,
        'valid_from' => today()->subMonth(),
        'valid_until' => null,
    ]);

    $created = app(RecurringRouteService::class)->generateForDate(today());

    expect($created)->toBe(1);

    $day = RouteDay::where('truck_id', $truck->id)->whereDate('route_date', today())->first();
    expect($day)->not->toBeNull()
        ->and($day->driver_id)->toBe($driver->id)
        ->and($day->status)->toBe(RouteStatus::Published)
        ->and($day->code)->toBe('R-'.today()->format('Ymd').'-C-09');
});

it('es idempotente: repetir generateForDate no duplica el RouteDay', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    Route::factory()->create(['truck_id' => $truck->id, 'driver_id' => $driver->id, 'valid_from' => today()->subMonth(), 'valid_until' => null]);

    $service = app(RecurringRouteService::class);
    $service->generateForDate(today());
    $second = $service->generateForDate(today());

    expect($second)->toBe(0)
        ->and(RouteDay::where('truck_id', $truck->id)->count())->toBe(1);
});

it('no genera RouteDay antes de valid_from ni después de valid_until', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    Route::factory()->create([
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

it('no genera RouteDay para fechas pasadas', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    Route::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $driver->id,
        'valid_from' => today()->subMonth(),
        'valid_until' => null,
    ]);

    $created = app(RecurringRouteService::class)->generateForDate(today()->subDay());

    expect($created)->toBe(0)
        ->and(RouteDay::where('truck_id', $truck->id)->exists())->toBeFalse();
});

it('no pisa un RouteDay ya creado a mano para esa ruta y esa fecha', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    $otroChofer = Driver::factory()->for(User::factory(), 'user')->create();

    $route = Route::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $driver->id,
        'valid_from' => today()->subMonth(),
        'valid_until' => null,
    ]);

    // Alguien ya generó/editó a mano el día de hoy con otro chofer (sustitución puntual).
    $manual = RouteDay::factory()->create([
        'route_id' => $route->id,
        'truck_id' => $truck->id,
        'driver_id' => $otroChofer->id,
        'route_date' => today(),
    ]);

    $created = app(RecurringRouteService::class)->generateForDate(today());

    expect($created)->toBe(0)
        ->and($manual->fresh()->driver_id)->toBe($otroChofer->id)
        ->and(RouteDay::where('route_id', $route->id)->whereDate('route_date', today())->count())->toBe(1);
});

it('generateHorizon(14) cubre desde hoy hasta +14 días', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    Route::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $driver->id,
        'valid_from' => today()->subMonth(),
        'valid_until' => null,
    ]);

    $created = app(RecurringRouteService::class)->generateHorizon(14);

    expect($created)->toBe(15) // hoy + 14 días siguientes
        ->and(RouteDay::where('truck_id', $truck->id)->whereDate('route_date', today()->addDays(14))->exists())->toBeTrue()
        ->and(RouteDay::where('truck_id', $truck->id)->whereDate('route_date', today()->addDays(15))->exists())->toBeFalse();
});
