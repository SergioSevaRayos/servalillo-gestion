<?php

use App\Livewire\Routes\Index;
use App\Models\Driver;
use App\Models\Route;
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

test('un chofer no puede acceder al listado de rutas', function () {
    $this->actingAs(makeUser('chofer'))->get('/rutas')->assertForbidden();
});
