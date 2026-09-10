<?php

use App\Enums\RouteStopStatus;
use App\Livewire\Routes\Index;
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
