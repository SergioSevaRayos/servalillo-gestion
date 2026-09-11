<?php

use App\Services\SgraClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function fakeSgraEnv(): void
{
    config()->set('servalillo.sgra.enabled', true);
    config()->set('servalillo.sgra.base_url', 'http://sgra.test');
    config()->set('servalillo.sgra.username', 'admin');
    config()->set('servalillo.sgra.password', 'secret');
}

it('devuelve [] sin llamar a la red si sgra.enabled es false', function () {
    config()->set('servalillo.sgra.enabled', false);

    Http::fake(fn () => Http::response()); // cualquier llamada real haría fallar el test

    expect(app(SgraClient::class)->tanks())->toBe([]);

    Http::assertNothingSent();
});

it('inicia sesión, lee cada depósito y normaliza el estado', function () {
    fakeSgraEnv();

    Http::fake([
        'sgra.test/api/login' => Http::response(['ok' => true]),
        'sgra.test/api/tanks' => Http::response(['tanks' => [
            ['id' => 't1', 'name' => 'Carablanca', 'color' => '#2563eb'],
            ['id' => 't2', 'name' => 'Ramón', 'color' => '#16a34a'],
        ], 'active_id' => 't1']),
        'sgra.test/api/tanks/t1/select' => Http::response(['ok' => true]),
        'sgra.test/api/tanks/t2/select' => Http::response(['ok' => true]),
        'sgra.test/api/current' => Http::sequence()
            ->push(['level_pct' => 61.3, 'online' => true, 'minutes_ago' => 2, 'alert_low' => false, 'tank_id' => 't1', 'tank_name' => 'Carablanca', 'tank_color' => '#2563eb'])
            ->push(['level_pct' => 12.0, 'online' => true, 'minutes_ago' => 1, 'alert_low' => true, 'tank_id' => 't2', 'tank_name' => 'Ramón', 'tank_color' => '#16a34a']),
    ]);

    $tanks = app(SgraClient::class)->tanks();

    expect($tanks)->toHaveCount(2)
        ->and($tanks[0])->toMatchArray(['id' => 't1', 'name' => 'Carablanca', 'fill_pct' => 61.3, 'online' => true, 'minutes_ago' => 2, 'alert_low' => false])
        ->and($tanks[1])->toMatchArray(['id' => 't2', 'name' => 'Ramón', 'fill_pct' => 12.0, 'alert_low' => true]);
});

it('devuelve [] si el login es rechazado', function () {
    fakeSgraEnv();

    Http::fake([
        'sgra.test/api/login' => Http::response(['error' => 'bad_credentials'], 401),
        'sgra.test/api/tanks' => Http::response(['tanks' => []]),
    ]);

    expect(app(SgraClient::class)->tanks())->toBe([]);
});

it('devuelve [] si la conexión falla (timeout/red)', function () {
    fakeSgraEnv();

    Http::fake(function () {
        throw new ConnectionException('no se pudo conectar');
    });

    expect(app(SgraClient::class)->tanks())->toBe([]);
});

it('marca un depósito sin datos si /api/current falla, sin descartar los demás', function () {
    fakeSgraEnv();

    Http::fake([
        'sgra.test/api/login' => Http::response(['ok' => true]),
        'sgra.test/api/tanks' => Http::response(['tanks' => [
            ['id' => 't1', 'name' => 'Carablanca'],
        ]]),
        'sgra.test/api/tanks/t1/select' => Http::response(['ok' => true]),
        'sgra.test/api/current' => Http::response(['error' => 'no_data']),
    ]);

    $tanks = app(SgraClient::class)->tanks();

    expect($tanks)->toHaveCount(1)
        ->and($tanks[0])->toMatchArray(['id' => 't1', 'name' => 'Carablanca', 'fill_pct' => null, 'online' => false]);
});

it('cachea el resultado y no repite las llamadas dentro del TTL', function () {
    fakeSgraEnv();
    config()->set('servalillo.sgra.cache_seconds', 60);

    Http::fake([
        'sgra.test/api/login' => Http::response(['ok' => true]),
        'sgra.test/api/tanks' => Http::response(['tanks' => [['id' => 't1', 'name' => 'Carablanca']]]),
        'sgra.test/api/tanks/t1/select' => Http::response(['ok' => true]),
        'sgra.test/api/current' => Http::response(['level_pct' => 50, 'online' => true, 'minutes_ago' => 1, 'alert_low' => false]),
    ]);

    $client = app(SgraClient::class);
    $client->tanks();
    $client->tanks();

    Http::assertSentCount(4); // login + tanks + select + current, UNA sola vez
});
