<?php

use App\Livewire\Trucks\Index;
use App\Models\Truck;
use App\Models\User;
use Livewire\Livewire;

test('un chofer no puede acceder al listado de camiones', function () {
    $user = User::factory()->create();
    $user->assignRole('chofer');

    $this->actingAs($user)->get('/camiones')->assertForbidden();
});

test('el administrador ve, busca y crea camiones', function () {
    Truck::factory()->create(['code' => 'C-99', 'plate' => '0000-ZZZ']);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->assertSee('C-99')
        ->set('search', 'C-99')
        ->assertSee('C-99')
        ->set('search', 'no-existe')
        ->assertDontSee('C-99')
        ->set('search', '')
        ->set('form.code', 'C-10')
        ->set('form.plate', '1111-ABC')
        ->set('form.odometer', 1000)
        ->call('save')
        ->assertHasNoErrors();

    expect(Truck::where('code', 'C-10')->exists())->toBeTrue();
});

test('no se puede duplicar el código de camión', function () {
    Truck::factory()->create(['code' => 'C-01']);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->set('form.code', 'C-01')
        ->set('form.plate', '2222-XYZ')
        ->call('save')
        ->assertHasErrors(['form.code']);
});

test('eliminar un camión lo hace desaparecer del listado', function () {
    $truck = Truck::factory()->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('delete', $truck)
        ->assertSuccessful();

    expect(Truck::find($truck->id))->toBeNull();
});
