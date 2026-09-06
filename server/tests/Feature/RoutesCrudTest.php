<?php

use App\Livewire\Routes\Index;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Truck;
use Livewire\Livewire;

test('el administrador crea una ruta', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(\App\Models\User::factory(), 'user')->create();

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
    $driver = Driver::factory()->for(\App\Models\User::factory(), 'user')->create();

    Route::factory()->create(['truck_id' => $truck->id, 'route_date' => '2026-09-10']);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->set('form.route_date', '2026-09-10')
        ->set('form.truck_id', $truck->id)
        ->set('form.driver_id', $driver->id)
        ->call('save')
        ->assertHasErrors(['form.truck_id']);
});

test('un chofer no puede acceder al listado de rutas', function () {
    $this->actingAs(makeUser('chofer'))->get('/rutas')->assertForbidden();
});
