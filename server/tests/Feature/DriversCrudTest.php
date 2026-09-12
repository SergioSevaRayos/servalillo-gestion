<?php

use App\Livewire\Drivers\Index;
use App\Models\Driver;
use App\Models\User;
use Livewire\Livewire;

test('el administrador crea un chofer (usuario + driver) a la vez', function () {
    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->set('form.name', 'Nuevo Chofer')
        ->set('form.email', 'nuevo.chofer@servalillo.test')
        ->set('form.password', 'clave-segura-123')
        ->set('form.employee_code', 'EMP-900')
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'nuevo.chofer@servalillo.test')->firstOrFail();

    expect($user->hasRole('chofer'))->toBeTrue()
        ->and(Driver::where('user_id', $user->id)->where('employee_code', 'EMP-900')->exists())->toBeTrue();
});

test('el email de chofer debe ser único', function () {
    $existing = Driver::factory()->for(User::factory()->state(['email' => 'ya@servalillo.test']), 'user')->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->set('form.name', 'Otro')
        ->set('form.email', 'ya@servalillo.test')
        ->set('form.password', 'clave-segura-123')
        ->set('form.employee_code', 'EMP-901')
        ->call('save')
        ->assertHasErrors(['form.email']);
});

test('se puede configurar una ubicación remota de fichaje para un chofer', function () {
    config()->set('servalillo.attendance.enabled', true);
    $driver = Driver::factory()->for(User::factory(), 'user')->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('edit', $driver)
        ->set('form.attendance_mode', 'remote')
        ->set('form.attendance_latitude', '40.4168')
        ->set('form.attendance_longitude', '-3.7038')
        ->set('form.attendance_radius_meters', '200')
        ->call('save')
        ->assertHasNoErrors();

    $driver->user->refresh();
    expect($driver->user->attendance_mode)->toBe('remote');
    expect((float) $driver->user->attendance_latitude)->toEqual(40.4168);
    expect($driver->user->attendance_radius_meters)->toBe(200);
});

test('editar un chofer sin contraseña no la modifica', function () {
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    $originalHash = $driver->user->password;

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('edit', $driver)
        ->set('form.name', 'Nombre Actualizado')
        ->call('save')
        ->assertHasNoErrors();

    expect($driver->user->fresh()->password)->toBe($originalHash)
        ->and($driver->user->fresh()->name)->toBe('Nombre Actualizado');
});
