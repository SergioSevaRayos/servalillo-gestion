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
