<?php

use App\Livewire\Maintenance\LoginLogs;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use OwenIt\Auditing\Models\Audit;

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

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});

it('el login también queda como evento en la Auditoría general', function () {
    $user = User::factory()->create();

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'password')
        ->call('login');

    $audit = Audit::where('auditable_type', User::class)
        ->where('auditable_id', $user->id)
        ->where('event', 'login')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($user->id);
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

it('permite seleccionar varios accesos y eliminarlos de golpe', function () {
    $user = makeUser('administrador');
    $a = LoginLog::create(['user_id' => $user->id, 'logged_in_at' => now()]);
    $b = LoginLog::create(['user_id' => $user->id, 'logged_in_at' => now()]);
    $c = LoginLog::create(['user_id' => $user->id, 'logged_in_at' => now()]);

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(LoginLogs::class)
        ->set('selected', [(string) $a->id, (string) $b->id])
        ->call('deleteSelected');

    expect(LoginLog::whereKey([$a->id, $b->id])->count())->toBe(0)
        ->and(LoginLog::whereKey($c->id)->exists())->toBeTrue();
});

it('purgar borra los accesos más antiguos que la retención configurada', function () {
    config()->set('servalillo.login_log_retention_days', 30);
    $user = makeUser('administrador');

    $viejo = LoginLog::create(['user_id' => $user->id, 'logged_in_at' => now()->subDays(45)]);
    $reciente = LoginLog::create(['user_id' => $user->id, 'logged_in_at' => now()->subDays(5)]);

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(LoginLogs::class)
        ->call('purgeOld');

    expect(LoginLog::whereKey($viejo->id)->exists())->toBeFalse()
        ->and(LoginLog::whereKey($reciente->id)->exists())->toBeTrue();
});

it('muestra como "conectado ahora" a quien tuvo actividad reciente', function () {
    $activo = makeUser('administrador');
    $activo->update(['name' => 'Activo Ahora']);
    $activo->forceFill(['last_seen_at' => now()->subMinute()])->saveQuietly();

    $inactivo = makeUser('administrador');
    $inactivo->update(['name' => 'Inactivo Hace Rato']);
    $inactivo->forceFill(['last_seen_at' => now()->subHours(2)])->saveQuietly();

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(LoginLogs::class)
        ->assertSee('Activo Ahora')
        ->assertDontSee('Inactivo Hace Rato');
});

it('el middleware actualiza last_seen_at en cada request, con margen de tolerancia', function () {
    $user = makeUser('administrador');
    expect($user->last_seen_at)->toBeNull();

    $this->actingAs($user)->get('/dashboard');
    $first = $user->fresh()->last_seen_at;
    expect($first)->not->toBeNull();

    // Segunda petición inmediata: no debe reescribir (dentro del margen de 60s).
    $this->actingAs($user)->get('/dashboard');
    expect($user->fresh()->last_seen_at->equalTo($first))->toBeTrue();

    // Pasado el margen, sí se actualiza.
    $user->forceFill(['last_seen_at' => now()->subMinutes(2)])->saveQuietly();
    $this->actingAs($user)->get('/dashboard');
    expect($user->fresh()->last_seen_at->gt(Carbon::now()->subMinute()))->toBeTrue();
});
