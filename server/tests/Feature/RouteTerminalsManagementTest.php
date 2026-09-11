<?php

use App\Livewire\Routes\Index;
use App\Models\Driver;
use App\Models\RouteTerminal;
use Livewire\Livewire;

test('oficina vincula, lista y revoca un terminal de una ruta', function () {
    $route = makePermanentRoute();

    $component = Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('openTerminals', $route->id)
        ->set('newTerminalLabel', 'Móvil de la cabina')
        ->call('createTerminal')
        ->assertHasNoErrors();

    $terminal = RouteTerminal::where('route_id', $route->id)->sole();
    expect($terminal->label)->toBe('Móvil de la cabina')
        ->and($terminal->token)->not->toBeEmpty()
        ->and($terminal->revoked_at)->toBeNull();

    expect($component->get('terminalsRoute')->terminals)->toHaveCount(1);

    $component->call('revokeTerminal', $terminal->id);

    expect($terminal->fresh()->revoked_at)->not->toBeNull();
});

test('un chofer no puede gestionar terminales de una ruta', function () {
    $route = makePermanentRoute();
    $chofer = makeUser('chofer');
    Driver::factory()->create(['user_id' => $chofer->id]);

    Livewire::actingAs($chofer)
        ->test(Index::class)
        ->call('openTerminals', $route->id)
        ->assertForbidden();
});

test('crear un terminal sin etiqueta lo deja sin etiqueta', function () {
    $route = makePermanentRoute();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Index::class)
        ->call('openTerminals', $route->id)
        ->call('createTerminal')
        ->assertHasNoErrors();

    $terminal = RouteTerminal::where('route_id', $route->id)->sole();
    expect($terminal->label)->toBeNull();
});
