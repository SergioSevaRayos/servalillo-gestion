<?php

use App\Livewire\Maintenance\LoginLogs;
use App\Models\LoginLog;
use App\Models\User;
use Livewire\Livewire;
use Livewire\Volt\Volt;

it('registra un acceso al iniciar sesión', function () {
    $user = User::factory()->create(['name' => 'Ana Login']);

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    $log = LoginLog::firstWhere('user_id', $user->id);
    expect($log)->not->toBeNull()
        ->and($log->ip)->not->toBeNull()
        ->and($log->logged_in_at)->not->toBeNull();
});

it('un intento de login fallido no registra acceso', function () {
    $user = User::factory()->create();

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'contraseña-mala')
        ->call('login');

    expect(LoginLog::where('user_id', $user->id)->exists())->toBeFalse();
});

it('mantenimiento ve el listado de accesos y filtra por nombre', function () {
    $ana = makeUser('administrador');
    $ana->update(['name' => 'Ana Filtro']);
    $bob = makeUser('mantenimiento');
    $bob->update(['name' => 'Bob Filtro']);

    LoginLog::create(['user_id' => $ana->id, 'ip' => '10.0.0.1', 'user_agent' => 'Chrome', 'logged_in_at' => now()]);
    LoginLog::create(['user_id' => $bob->id, 'ip' => '10.0.0.2', 'user_agent' => 'Firefox', 'logged_in_at' => now()]);

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(LoginLogs::class)
        ->assertSee('Ana Filtro')
        ->assertSee('Bob Filtro')
        ->set('search', 'Ana Filtro')
        ->assertSee('Ana Filtro')
        ->assertDontSee('Bob Filtro');
});

it('un acceso sigue apareciendo aunque se borre la cuenta', function () {
    $user = makeUser('administrador');
    LoginLog::create(['user_id' => $user->id, 'ip' => '10.0.0.1', 'logged_in_at' => now()]);

    $user->delete();

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(LoginLogs::class)
        ->assertSee('Cuenta eliminada');
});

it('un administrador no puede ver los accesos', function () {
    $this->actingAs(makeUser('administrador'))->get('/mantenimiento/accesos')->assertForbidden();
});

it('un chofer no puede ver los accesos', function () {
    $this->actingAs(makeUser('chofer'))->get('/mantenimiento/accesos')->assertForbidden();
});
