<?php

use App\Livewire\Routes\Assignments;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Truck;
use App\Models\TruckAssignment;
use App\Models\User;
use Livewire\Livewire;

test('un chofer no puede acceder a las asignaciones', function () {
    $user = User::factory()->create();
    $user->assignRole('chofer');

    $this->actingAs($user)->get('/rutas/asignaciones')->assertForbidden();
});

test('el administrador crea una asignación indefinida', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Assignments::class)
        ->set('form.truck_id', $truck->id)
        ->set('form.driver_id', $driver->id)
        ->set('form.valid_from', today()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    $assignment = TruckAssignment::where('truck_id', $truck->id)->first();
    expect($assignment)->not->toBeNull()
        ->and($assignment->valid_until)->toBeNull();
});

test('el administrador crea una asignación con fecha de fin', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Assignments::class)
        ->set('form.truck_id', $truck->id)
        ->set('form.driver_id', $driver->id)
        ->set('form.valid_from', today()->toDateString())
        ->set('form.valid_until', today()->addMonth()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    expect(TruckAssignment::where('truck_id', $truck->id)->first()->valid_until->toDateString())
        ->toBe(today()->addMonth()->toDateString());
});

test('rechaza una fecha de fin anterior a la de inicio', function () {
    $truck = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Assignments::class)
        ->set('form.truck_id', $truck->id)
        ->set('form.driver_id', $driver->id)
        ->set('form.valid_from', today()->toDateString())
        ->set('form.valid_until', today()->subDay()->toDateString())
        ->call('save')
        ->assertHasErrors(['form.valid_until']);
});

test('rechaza dos asignaciones solapadas para el mismo camión', function () {
    $truck = Truck::factory()->create();
    $driverA = Driver::factory()->for(User::factory(), 'user')->create();
    $driverB = Driver::factory()->for(User::factory(), 'user')->create();

    TruckAssignment::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $driverA->id,
        'valid_from' => today(),
        'valid_until' => null,
    ]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Assignments::class)
        ->set('form.truck_id', $truck->id)
        ->set('form.driver_id', $driverB->id)
        ->set('form.valid_from', today()->addWeek()->toDateString())
        ->call('save')
        ->assertHasErrors(['form.truck_id']);
});

test('rechaza dos asignaciones solapadas para el mismo chofer', function () {
    $truckA = Truck::factory()->create();
    $truckB = Truck::factory()->create();
    $driver = Driver::factory()->for(User::factory(), 'user')->create();

    TruckAssignment::factory()->create([
        'truck_id' => $truckA->id,
        'driver_id' => $driver->id,
        'valid_from' => today(),
        'valid_until' => null,
    ]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Assignments::class)
        ->set('form.truck_id', $truckB->id)
        ->set('form.driver_id', $driver->id)
        ->set('form.valid_from', today()->addWeek()->toDateString())
        ->call('save')
        ->assertHasErrors(['form.driver_id']);
});

test('permite crear una asignación que empieza justo cuando termina otra', function () {
    $truck = Truck::factory()->create();
    $driverA = Driver::factory()->for(User::factory(), 'user')->create();
    $driverB = Driver::factory()->for(User::factory(), 'user')->create();

    TruckAssignment::factory()->create([
        'truck_id' => $truck->id,
        'driver_id' => $driverA->id,
        'valid_from' => today()->subMonth(),
        'valid_until' => today(),
    ]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Assignments::class)
        ->set('form.truck_id', $truck->id)
        ->set('form.driver_id', $driverB->id)
        ->set('form.valid_from', today()->addDay()->toDateString())
        ->call('save')
        ->assertHasNoErrors();
});

test('finalizar una asignación fija su fecha de fin a hoy', function () {
    $assignment = TruckAssignment::factory()->create(['valid_until' => null]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Assignments::class)
        ->call('finalize', $assignment)
        ->assertSuccessful();

    expect($assignment->fresh()->valid_until->toDateString())->toBe(today()->toDateString());
});

test('eliminar una asignación es un soft delete y no toca las rutas ya generadas', function () {
    $assignment = TruckAssignment::factory()->create();
    $route = makeRoute(today()->toDateString());

    Livewire::actingAs(makeUser('administrador'))
        ->test(Assignments::class)
        ->call('delete', $assignment)
        ->assertSuccessful();

    expect(TruckAssignment::find($assignment->id))->toBeNull()
        ->and(TruckAssignment::withTrashed()->find($assignment->id))->not->toBeNull()
        ->and(Route::find($route->id))->not->toBeNull();
});
