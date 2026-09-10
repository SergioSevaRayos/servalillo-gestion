<?php

use App\Models\User;

it('crea un usuario de gestión con su rol', function () {
    $this->artisan('servalillo:crear-usuario', [
        '--name' => 'Ana Jefa',
        '--email' => 'ana@empresa.com',
        '--password' => 'contrasena-larga',
        '--rol' => 'administrador',
    ])->assertSuccessful();

    $user = User::firstWhere('email', 'ana@empresa.com');

    expect($user)->not->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasRole('administrador'))->toBeTrue()
        ->and($user->password)->not->toBe('contrasena-larga'); // hasheada
});

it('crea un usuario de mantenimiento', function () {
    $this->artisan('servalillo:crear-usuario', [
        '--name' => 'Mario Técnico', '--email' => 'mario@empresa.com',
        '--password' => 'otra-contrasena', '--rol' => 'mantenimiento',
    ])->assertSuccessful();

    expect(User::firstWhere('email', 'mario@empresa.com')->hasRole('mantenimiento'))->toBeTrue();
});

it('rechaza un email ya usado', function () {
    User::factory()->create(['email' => 'repe@empresa.com']);

    $this->artisan('servalillo:crear-usuario', [
        '--name' => 'X', '--email' => 'repe@empresa.com',
        '--password' => 'contrasena-larga', '--rol' => 'administrador',
    ])->assertFailed();

    expect(User::where('email', 'repe@empresa.com')->count())->toBe(1);
});

it('rechaza una contraseña corta', function () {
    $this->artisan('servalillo:crear-usuario', [
        '--name' => 'X', '--email' => 'corta@empresa.com',
        '--password' => 'corta', '--rol' => 'administrador',
    ])->assertFailed();

    expect(User::where('email', 'corta@empresa.com')->exists())->toBeFalse();
});

it('rechaza un rol que no sea administrador ni mantenimiento', function () {
    $this->artisan('servalillo:crear-usuario', [
        '--name' => 'X', '--email' => 'chofer@empresa.com',
        '--password' => 'contrasena-larga', '--rol' => 'chofer',
    ])->assertFailed();
});
