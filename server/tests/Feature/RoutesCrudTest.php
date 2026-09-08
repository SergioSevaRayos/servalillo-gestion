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
        ->set('form.route_date', '2026-09-10')
        ->set('form.truck_id', $truck->id)
        ->set('form.driver_id', $driver->id)
        ->set('form.status', 'draft')
        ->call('save')
        ->assertHasNoErrors();

    expect(Route::where('truck_id', $truck->id)->where('route_date', '2026-09-10')->exists())->toBeTrue();
});

test('no se puede crear dos rutas el mismo día para el mismo camión', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();

    Route::factory()->create(['truck_id' => $truck->id, 'route_date' => '2026-09-10']);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->set('form.route_date', '2026-09-10')
        ->set('form.truck_id', $truck->id)
        ->set('form.driver_id', $driver->id)
        ->call('save')
        ->assertHasErrors(['form.truck_id']);
});

test('editar la fecha, el camión o el tipo de servicio regenera el código de la ruta', function () {
    $c04 = Truck::factory()->create(['code' => 'C-04']);
    $c09 = Truck::factory()->create(['code' => 'C-09']);
    $driver = Driver::factory()->for(User::factory(), 'user')->create();

    $route = Route::factory()->create([
        'truck_id' => $c04->id, 'driver_id' => $driver->id,
        'route_date' => '2026-09-08', 'service_kind' => 'reparto',
        'code' => 'R-20260908-C-04',
    ]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('edit', $route)
        ->set('form.service_kind', 'viaje')
        ->set('form.route_date', '2026-09-11')
        ->set('form.truck_id', $c09->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($route->fresh()->code)->toBe('V-20260911-C-09');
});

test('eliminar una ruta manda sus paradas pendientes a "Sin asignar"', function () {
    $route = Route::factory()->create();
    $pendingA = RouteStop::factory()->for($route)->create(['position' => 1, 'status' => RouteStopStatus::Pending]);
    $pendingB = RouteStop::factory()->for($route)->create(['position' => 2, 'status' => RouteStopStatus::Pending]);
    $done = RouteStop::factory()->for($route)->create(['position' => 3, 'status' => RouteStopStatus::Completed]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('delete', $route)
        ->assertHasNoErrors();

    expect($route->fresh()->trashed())->toBeTrue()
        ->and($pendingA->fresh()->route_id)->toBeNull()
        ->and($pendingB->fresh()->route_id)->toBeNull()
        ->and($done->fresh()->route_id)->toBe($route->id); // las cerradas se quedan con la ruta
});

test('un chofer no puede acceder al listado de rutas', function () {
    $this->actingAs(makeUser('chofer'))->get('/rutas')->assertForbidden();
});
