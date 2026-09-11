<?php

use App\Livewire\Sgra\Index;
use App\Models\Driver;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('muestra el panel de depósitos al administrador', function () {
    $this->actingAs(makeUser('administrador'))
        ->get('/depositos')
        ->assertOk()
        ->assertSee('Depósitos');
});

it('deja entrar a mantenimiento (superusuario técnico)', function () {
    $this->actingAs(makeUser('mantenimiento'))->get('/depositos')->assertOk();
});

it('no deja el panel a un chofer', function () {
    $chofer = makeUser('chofer');
    Driver::factory()->create(['user_id' => $chofer->id]);

    $this->actingAs($chofer)->get('/depositos')->assertForbidden();
});

it('el componente aborta 403 si el usuario no tiene sgra.view', function () {
    $this->actingAs(makeUser('chofer'));
    Livewire::test(Index::class)->assertForbidden();
});

it('muestra un aviso discreto si SGRA no responde', function () {
    config()->set('servalillo.sgra.enabled', false);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->assertSee('No se ha podido conectar con los depósitos SGRA ahora mismo');
});

it('muestra el nivel de cada depósito cuando SGRA responde', function () {
    config()->set('servalillo.sgra.enabled', true);
    config()->set('servalillo.sgra.base_url', 'http://sgra.test');

    Http::fake([
        'sgra.test/api/login' => Http::response(['ok' => true]),
        'sgra.test/api/tanks' => Http::response(['tanks' => [['id' => 't1', 'name' => 'Carablanca']]]),
        'sgra.test/api/tanks/t1/select' => Http::response(['ok' => true]),
        'sgra.test/api/current' => Http::response(['level_pct' => 61.3, 'online' => true, 'minutes_ago' => 2, 'alert_low' => false, 'tank_id' => 't1', 'tank_name' => 'Carablanca']),
    ]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->assertSee('Carablanca')
        ->assertSee('61,3%');
});
