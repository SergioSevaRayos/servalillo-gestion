<?php

use App\Models\User;

test('con la cookie theme=dark, el html se sirve ya con la clase dark', function () {
    $user = User::factory()->create();
    $user->assignRole('administrador');

    $response = $this->actingAs($user)
        ->withUnencryptedCookies(['theme' => 'dark'])
        ->get('/dashboard');

    $response->assertOk();
    $response->assertSee('<html lang="es" class="dark">', escape: false);
});

test('sin cookie de tema, el html no fuerza la clase dark en el servidor', function () {
    $user = User::factory()->create();
    $user->assignRole('administrador');

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertSee('<html lang="es" class="">', escape: false);
});
