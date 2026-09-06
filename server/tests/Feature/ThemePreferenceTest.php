<?php

use App\Models\User;

test('un invitado puede fijar el tema y recibe la cookie', function () {
    $response = $this->postJson('/theme', ['theme' => 'dark']);

    $response->assertNoContent();
    $response->assertCookie('theme', 'dark', encrypted: false);
});

test('un usuario autenticado persiste su preferencia de tema en la BD', function () {
    $user = User::factory()->create(['theme_preference' => 'system']);

    $this->actingAs($user)->postJson('/theme', ['theme' => 'light'])->assertNoContent();

    expect($user->fresh()->theme_preference)->toBe(\App\Enums\ThemePreference::Light);
});

test('rechaza un valor de tema no soportado', function () {
    $this->postJson('/theme', ['theme' => 'neon'])->assertInvalid('theme');
});
