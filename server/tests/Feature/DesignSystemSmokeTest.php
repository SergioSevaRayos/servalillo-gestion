<?php

use App\Models\Driver;
use App\Models\User;

test('el dashboard renderiza con el sistema de diseño para un administrador', function () {
    $user = User::factory()->create();
    $user->assignRole('administrador');

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertSee('glass', escape: false); // nav + KPIs usan la clase .glass
    $response->assertSee('Rutas activas hoy');
});

test('la guía de estilo carga todos los componentes sin errores', function () {
    $user = User::factory()->create();
    $user->assignRole('administrador');

    $response = $this->actingAs($user)->get('/style-guide');

    $response->assertOk();
    $response->assertSee('table-responsive', escape: false);
});

test('los 4 módulos del Bloque 3 cargan para un administrador', function () {
    $user = User::factory()->create();
    $user->assignRole('administrador');

    $this->actingAs($user)->get('/chofers')->assertOk()->assertSee('Nuevo chofer');
    $this->actingAs($user)->get('/camiones')->assertOk()->assertSee('Nuevo camión');
    $this->actingAs($user)->get('/rutas')->assertOk()->assertSee('Sin asignar');
    $this->actingAs($user)->get('/rutas/listado')->assertOk()->assertSee('Nueva ruta');
    $this->actingAs($user)->get('/usuarios')->assertOk()->assertSee('Nuevo usuario');
});

test('la web del chofer no usa cristal en el contenido operativo', function () {
    $user = User::factory()->create();
    $user->assignRole('chofer');
    Driver::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/chofer/ruta');

    $response->assertOk();
    $response->assertSee('Mi ruta de hoy');
    // El contenido operativo del chofer va sobre .surface, nunca .glass.
    $response->assertSee('surface', escape: false);
});
