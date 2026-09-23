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

test('un error de validación se pinta de verdad bajo el campo, no solo en el error bag (2026-09-23)', function () {
    // Bug real: Livewire\Form guarda los errores como "form.email", pero <x-ui.input
    // name="email"> buscaba $errors->first('email') (sin el prefijo "form.") y nunca
    // encontraba nada — el campo nunca se pintaba de rojo ni mostraba el mensaje, en
    // NINGÚN formulario de la app (Clientes, Usuarios, Chofers, Camiones...), aunque
    // assertHasErrors() diera positivo. Ver components/ui/input.blade.php.
    $test = Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->set('form.name', 'Sin Email')
        ->set('form.email', '') // required
        ->call('save');

    $test->assertHasErrors('form.email');
    expect($test->html())
        ->toContain('obligatorio')
        ->toContain('border-rose-400');
});

test('un chofer no puede acceder al listado de usuarios', function () {
    $this->actingAs(makeUser('chofer'))->get('/usuarios')->assertForbidden();
});

test('se puede configurar una ubicación remota de fichaje para un administrador', function () {
    config()->set('servalillo.attendance.enabled', true);
    $admin = makeUser('administrador');

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('edit', $admin->id)
        ->set('form.attendance_mode', 'remote')
        ->set('form.attendance_latitude', '40.4168')
        ->set('form.attendance_longitude', '-3.7038')
        ->set('form.attendance_radius_meters', '200')
        ->call('save')
        ->assertHasNoErrors();

    $admin->refresh();
    expect($admin->attendance_mode)->toBe('remote');
    expect((float) $admin->attendance_latitude)->toEqual(40.4168);
    expect($admin->attendance_radius_meters)->toBe(200);
});

test('se pueden fijar las horas semanales contratadas de un administrador', function () {
    config()->set('servalillo.attendance.enabled', true);
    $admin = makeUser('administrador');

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('edit', $admin->id)
        ->set('form.weekly_contracted_hours', '25')
        ->call('save')
        ->assertHasNoErrors();

    $admin->refresh();
    expect((float) $admin->weekly_contracted_hours)->toEqual(25.0);
});

test('las horas semanales contratadas fuera de rango no se guardan', function () {
    config()->set('servalillo.attendance.enabled', true);
    $admin = makeUser('administrador');

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('edit', $admin->id)
        ->set('form.weekly_contracted_hours', '81')
        ->call('save')
        ->assertHasErrors(['form.weekly_contracted_hours']);
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
