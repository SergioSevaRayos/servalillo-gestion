<?php

use App\Models\Driver;
use App\Models\Route;
use App\Models\Truck;

// makeUser() está definido globalmente en tests/Pest.php

test('administrador puede gestionar camiones pero no ver logs de sistema', function () {
    $admin = makeUser('administrador');

    expect($admin->can('trucks.create'))->toBeTrue()
        ->and($admin->can('system_logs.view'))->toBeFalse()
        ->and($admin->can('audits.view'))->toBeFalse();
});

test('mantenimiento tiene acceso total via Gate::before', function () {
    $maint = makeUser('mantenimiento');
    $truck = Truck::factory()->create();

    expect($maint->can('system_logs.view'))->toBeTrue()
        ->and($maint->can('audits.view'))->toBeTrue()
        ->and($maint->can('delete', $truck))->toBeTrue();
});

test('chofer solo puede operar su propia ruta', function () {
    $ownUser = makeUser('chofer');
    $otherUser = makeUser('chofer');
    $ownDriver = Driver::factory()->create(['user_id' => $ownUser->id]);
    $otherDriver = Driver::factory()->create(['user_id' => $otherUser->id]);

    $route = Route::factory()->create(['driver_id' => $ownDriver->id]);

    expect($ownUser->can('operate', $route))->toBeTrue()
        ->and($otherUser->can('operate', $route))->toBeFalse()
        ->and($ownUser->can('trucks.create'))->toBeFalse()
        ->and($ownUser->can('routes.optimize.own'))->toBeTrue()
        ->and($ownUser->can('optimizeOwn', $route))->toBeTrue()
        ->and($otherUser->can('optimizeOwn', $route))->toBeFalse()
        ->and($ownUser->can('routes.reorder_stops'))->toBeFalse();
});

test('la web de gestion rechaza al chofer', function () {
    $this->actingAs(makeUser('chofer'))
        ->get('/dashboard')
        ->assertForbidden();
});

test('el chofer es redirigido a su ruta desde /home', function () {
    $user = makeUser('chofer');
    Driver::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get('/home')->assertRedirect(route('chofer.today'));
});

test('un usuario inactivo no puede navegar', function () {
    $user = makeUser('administrador');
    $user->update(['is_active' => false]);

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));
});
