<?php

use App\Livewire\Users\Index;
use App\Models\User;
use Livewire\Livewire;

test('el listado de usuarios solo muestra personal, no chofers', function () {
    $driver = makeUser('chofer');
    $staff = makeUser('mantenimiento');

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->assertSee($staff->name)
        ->assertDontSee($driver->name);
});

test('el administrador crea una cuenta de mantenimiento', function () {
    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->set('form.name', 'Nuevo Soporte')
        ->set('form.email', 'soporte2@servalillo.test')
        ->set('form.password', 'clave-segura-123')
        ->set('form.role', 'mantenimiento')
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'soporte2@servalillo.test')->firstOrFail();

    expect($user->hasRole('mantenimiento'))->toBeTrue();
});

test('un chofer no puede acceder al listado de usuarios', function () {
    $this->actingAs(makeUser('chofer'))->get('/usuarios')->assertForbidden();
});

test('se puede reutilizar el email de una cuenta ya borrada', function () {
    $borrado = User::factory()->create(['email' => 'reciclado@servalillo.test']);
    $borrado->assignRole('administrador');
    $borrado->delete();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->set('form.name', 'Cuenta Nueva')
        ->set('form.email', 'reciclado@servalillo.test')
        ->set('form.password', 'clave-segura-123')
        ->set('form.role', 'administrador')
        ->call('save')
        ->assertHasNoErrors();

    expect(User::where('email', 'reciclado@servalillo.test')->count())->toBe(1);
});
