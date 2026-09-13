<?php

use App\Models\Attendance;

beforeEach(function () {
    config()->set('servalillo.company.name', 'Servalillo S.L.');
    config()->set('servalillo.company.tax_id', 'B12345678');
});

it('devuelve 404 si el bloque de fichaje está desactivado', function () {
    config()->set('servalillo.attendance.enabled', false);

    $this->actingAs(makeUser('administrador'))
        ->get(route('attendance.export-json'))
        ->assertNotFound();
});

it('no deja entrar a un chofer sin el permiso attendance.manage', function () {
    config()->set('servalillo.attendance.enabled', true);

    $this->actingAs(makeUser('chofer'))
        ->get(route('attendance.export-json'))
        ->assertForbidden();
});

it('deja entrar a administrador y a mantenimiento y devuelve el JSON interoperable del rango pedido', function () {
    config()->set('servalillo.attendance.enabled', true);
    $user = makeUser('chofer');
    Attendance::factory()->for($user)->closed()->create(['date' => '2026-09-10']);
    Attendance::factory()->for($user)->closed()->create(['date' => '2026-08-01']); // fuera del rango pedido

    foreach (['administrador', 'mantenimiento'] as $role) {
        $response = $this->actingAs(makeUser($role))
            ->get(route('attendance.export-json', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertJsonStructure(['empresa' => ['nombre', 'cif'], 'jornadas']);

        expect($response->json('jornadas'))->toHaveCount(1);
        expect($response->json('jornadas.0.fecha'))->toBe('2026-09-10');
    }
});

it('filtra por user_id cuando se pide', function () {
    config()->set('servalillo.attendance.enabled', true);
    $user = makeUser('chofer');
    $other = makeUser('administrador');
    Attendance::factory()->for($user)->closed()->create(['date' => '2026-09-10']);
    Attendance::factory()->for($other)->closed()->create(['date' => '2026-09-10']);

    $response = $this->actingAs(makeUser('administrador'))
        ->get(route('attendance.export-json', ['from' => '2026-09-01', 'to' => '2026-09-30', 'user_id' => $user->id]))
        ->assertOk();

    expect($response->json('jornadas'))->toHaveCount(1);
    expect($response->json('jornadas.0.empleado.nombre'))->toBe($user->name);
});
