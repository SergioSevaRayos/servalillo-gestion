<?php

use App\Enums\RouteStopStatus;
use App\Enums\ServiceKind;
use App\Livewire\Routes\Board;
use App\Models\Route;
use App\Models\RouteStop;
use Livewire\Livewire;

// makeRoute() está definido globalmente en tests/Pest.php

test('un chofer no puede acceder al tablero', function () {
    $this->actingAs(makeUser('chofer'))->get('/rutas')->assertForbidden();
});

test('el tablero muestra una columna por ruta del día y la columna sin asignar', function () {
    $route = makeRoute('2026-09-10');
    RouteStop::factory()->create(['route_id' => $route->id, 'customer_name' => 'Cliente A']);
    RouteStop::factory()->create(['route_id' => null, 'customer_name' => 'Cliente Backlog']);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->assertSee($route->truck->code)
        ->assertSee('Cliente A')
        ->assertSee('Sin asignar')
        ->assertSee('Cliente Backlog');
});

test('crear una parada la deja en la columna correcta', function () {
    $route = makeRoute('2026-09-10');

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('openCreateStop', $route->id)
        ->set('form.customer_name', 'Nuevo Cliente')
        ->call('saveStop')
        ->assertHasNoErrors();

    expect(RouteStop::where('customer_name', 'Nuevo Cliente')->first()->route_id)->toBe($route->id);
});

test('crear una parada sin ruta la deja en sin asignar', function () {
    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('openCreateStop', null)
        ->set('form.customer_name', 'Cliente Suelto')
        ->call('saveStop')
        ->assertHasNoErrors();

    expect(RouteStop::where('customer_name', 'Cliente Suelto')->first()->route_id)->toBeNull();
});

test('arrastrar una parada de sin asignar a una ruta la reasigna', function () {
    $route = makeRoute('2026-09-10');
    $stop = RouteStop::factory()->create(['route_id' => null, 'position' => 1]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('reorderStops', null, [], $route->id, [$stop->id]);

    expect($stop->fresh())
        ->route_id->toBe($route->id)
        ->position->toBe(1);
});

test('reordenar dentro de la misma columna actualiza las posiciones', function () {
    $route = makeRoute('2026-09-10');
    $a = RouteStop::factory()->create(['route_id' => $route->id, 'position' => 1]);
    $b = RouteStop::factory()->create(['route_id' => $route->id, 'position' => 2]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('reorderStops', $route->id, [$b->id, $a->id], $route->id, [$b->id, $a->id]);

    expect($b->fresh()->position)->toBe(1)
        ->and($a->fresh()->position)->toBe(2);
});

test('no se puede reasignar a una ruta inexistente', function () {
    $stop = RouteStop::factory()->create(['route_id' => null]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('reorderStops', null, [], 999999, [$stop->id])
        ->assertStatus(422);
});

test('no se puede mover una parada completada', function () {
    $route = makeRoute('2026-09-10');
    $stop = RouteStop::factory()->create([
        'route_id' => null,
        'position' => 1,
        'status' => RouteStopStatus::Completed,
    ]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('reorderStops', null, [], $route->id, [$stop->id])
        ->assertStatus(422);

    expect($stop->fresh()->route_id)->toBeNull();
});

test('reordenar una columna no toca las paradas completadas que ya estaban ahí', function () {
    $route = makeRoute('2026-09-10');
    $done = RouteStop::factory()->create(['route_id' => $route->id, 'position' => 1, 'status' => RouteStopStatus::Completed]);
    $a = RouteStop::factory()->create(['route_id' => $route->id, 'position' => 2]);
    $b = RouteStop::factory()->create(['route_id' => $route->id, 'position' => 3]);

    // El "done" se queda donde estaba (posición 1); solo se intercambian a y b.
    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('reorderStops', $route->id, [$done->id, $b->id, $a->id], $route->id, [$done->id, $b->id, $a->id])
        ->assertStatus(200);

    expect($done->fresh()->position)->toBe(1)
        ->and($b->fresh()->position)->toBe(2)
        ->and($a->fresh()->position)->toBe(3);
});

test('eliminar una parada desde el tablero', function () {
    $stop = RouteStop::factory()->create(['route_id' => null]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('deleteStop', $stop);

    expect(RouteStop::find($stop->id))->toBeNull();
});

test('el filtro Reparto/Viajes separa rutas y backlog por tipo', function () {
    $reparto = makeRoute('2026-09-10');
    RouteStop::factory()->create(['route_id' => $reparto->id, 'customer_name' => 'Parada Reparto']);
    RouteStop::factory()->create(['route_id' => null, 'customer_name' => 'Backlog Reparto']);

    $viaje = Route::factory()->trip()->create(['route_date' => '2026-09-10', 'name' => 'Ruta Viaje']);
    RouteStop::factory()->trip()->create(['route_id' => $viaje->id, 'customer_name' => 'Parada Viaje']);
    RouteStop::factory()->trip()->create(['route_id' => null, 'customer_name' => 'Backlog Viaje']);

    $c = Livewire::actingAs(makeUser('administrador'))->test(Board::class)->set('date', '2026-09-10');

    // Por defecto: Reparto.
    $c->assertSet('kind', 'reparto')
        ->assertSee('Parada Reparto')->assertSee('Backlog Reparto')
        ->assertDontSee('Parada Viaje')->assertDontSee('Backlog Viaje');

    $c->call('setKind', 'viaje')
        ->assertSee('Parada Viaje')->assertSee('Backlog Viaje')
        ->assertDontSee('Parada Reparto')->assertDontSee('Backlog Reparto');
});

test('una parada nueva hereda el tipo del filtro activo', function () {
    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-09-10')
        ->call('setKind', 'viaje')
        ->call('openCreateStop', null)
        ->set('form.customer_name', 'Viaje Suelto')
        ->call('saveStop')
        ->assertHasNoErrors();

    expect(RouteStop::firstWhere('customer_name', 'Viaje Suelto')->service_kind)
        ->toBe(ServiceKind::Viaje);
});
