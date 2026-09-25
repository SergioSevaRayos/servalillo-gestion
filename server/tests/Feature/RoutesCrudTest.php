<?php

use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Livewire\Routes\Index;
use App\Models\Device;
use App\Models\Driver;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Truck;
use App\Models\User;
use Livewire\Livewire;

test('el administrador crea una ruta', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->set('form.truck_id', $truck->id)
        ->set('form.driver_id', $driver->id)
        ->set('form.service_kind', 'reparto')
        ->set('form.valid_from', '2026-09-10')
        ->call('save')
        ->assertHasNoErrors();

    expect(Route::where('truck_id', $truck->id)->where('driver_id', $driver->id)->exists())->toBeTrue();
});

test('no se puede crear dos rutas con fechas solapadas para el mismo camión', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();

    Route::factory()->create(['truck_id' => $truck->id, 'valid_from' => '2026-09-01', 'valid_until' => null]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->set('form.truck_id', $truck->id)
        ->set('form.driver_id', $driver->id)
        ->set('form.valid_from', '2026-09-10')
        ->call('save')
        ->assertHasErrors(['form.truck_id']);
});

test('editar el chofer de una ruta la actualiza', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    $otroChofer = Driver::factory()->for(User::factory(), 'user')->create();

    $route = Route::factory()->create([
        'truck_id' => $truck->id, 'driver_id' => $driver->id,
        'valid_from' => '2026-09-01', 'valid_until' => null,
    ]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('edit', $route)
        ->set('form.driver_id', $otroChofer->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($route->fresh()->driver_id)->toBe($otroChofer->id);
});

test('editar el chofer/camión de una ruta propaga el cambio a los RouteDay de hoy y futuros (2026-09-25)', function () {
    $route = Route::factory()->create(['valid_from' => today()->subMonth(), 'valid_until' => null]);
    $hoy = makeRouteDay($route, today()->toDateString());
    $futuro = makeRouteDay($route, today()->addDays(3)->toDateString());

    $otroChofer = Driver::factory()->for(User::factory(), 'user')->create();
    $otroCamion = Truck::factory()->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('edit', $route)
        ->set('form.driver_id', $otroChofer->id)
        ->set('form.truck_id', $otroCamion->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($hoy->fresh()->driver_id)->toBe($otroChofer->id)
        ->and($hoy->fresh()->truck_id)->toBe($otroCamion->id)
        ->and($futuro->fresh()->driver_id)->toBe($otroChofer->id)
        ->and($futuro->fresh()->truck_id)->toBe($otroCamion->id);
});

test('editar el chofer de una ruta no toca un RouteDay ya completado ni el historial pasado', function () {
    $route = Route::factory()->create(['valid_from' => today()->subMonth(), 'valid_until' => null]);
    $driverOriginal = $route->driver_id;
    $completadoHoy = makeRouteDay($route, today()->toDateString());
    $completadoHoy->update(['status' => RouteStatus::Completed]);
    $pasado = makeRouteDay($route, today()->subDays(5)->toDateString());

    $otroChofer = Driver::factory()->for(User::factory(), 'user')->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('edit', $route)
        ->set('form.driver_id', $otroChofer->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($completadoHoy->fresh()->driver_id)->toBe($driverOriginal)
        ->and($pasado->fresh()->driver_id)->toBe($driverOriginal);
});

test('editar el chofer de una ruta reasigna también su dispositivo GPS si el nuevo chofer no tiene uno', function () {
    $route = Route::factory()->create(['valid_from' => today()->subMonth(), 'valid_until' => null]);
    $driverOriginal = $route->driver_id;
    $device = Device::factory()->create(['driver_id' => $driverOriginal]);
    makeRouteDay($route, today()->toDateString());

    $otroChofer = Driver::factory()->for(User::factory(), 'user')->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('edit', $route)
        ->set('form.driver_id', $otroChofer->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($device->fresh()->driver_id)->toBe($otroChofer->id);
});

test('editar el chofer de una ruta no le roba el dispositivo a un chofer que ya tiene el suyo', function () {
    $route = Route::factory()->create(['valid_from' => today()->subMonth(), 'valid_until' => null]);
    $driverOriginal = $route->driver_id;
    $deviceOriginal = Device::factory()->create(['driver_id' => $driverOriginal]);
    makeRouteDay($route, today()->toDateString());

    $otroChofer = Driver::factory()->for(User::factory(), 'user')->create();
    $deviceDelOtro = Device::factory()->create(['driver_id' => $otroChofer->id]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('edit', $route)
        ->set('form.driver_id', $otroChofer->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($deviceOriginal->fresh()->driver_id)->toBe($driverOriginal)
        ->and($deviceDelOtro->fresh()->driver_id)->toBe($otroChofer->id);
});

test('editar una ruta sin cambiar chofer/camión no toca los RouteDay ni el dispositivo', function () {
    $route = Route::factory()->create(['valid_from' => today()->subMonth(), 'valid_until' => null]);
    $driverOriginal = $route->driver_id;
    $day = makeRouteDay($route, today()->toDateString());
    $updatedAt = $day->updated_at;

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('edit', $route)
        ->set('form.notes', 'Nota nueva')
        ->call('save')
        ->assertHasNoErrors();

    expect($day->fresh()->driver_id)->toBe($driverOriginal)
        ->and($day->fresh()->updated_at->eq($updatedAt))->toBeTrue();
});

test('eliminar una ruta permanente no toca el historial de días ya generados', function () {
    $route = Route::factory()->create();
    $day = makeRouteDay($route, '2026-09-10');
    $stop = RouteStop::factory()->for($day, 'route')->create(['status' => RouteStopStatus::Pending]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('delete', $route)
        ->assertHasNoErrors();

    expect($route->fresh()->trashed())->toBeTrue()
        ->and($day->fresh())->not->toBeNull()
        ->and($day->fresh()->route_id)->toBe($route->id)
        ->and($stop->fresh()->route_id)->toBe($day->id);
});

test('un chofer no puede acceder al listado de rutas', function () {
    $this->actingAs(makeUser('chofer'))->get('/rutas')->assertForbidden();
});
