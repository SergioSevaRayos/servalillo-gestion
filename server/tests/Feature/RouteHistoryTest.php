<?php

use App\Enums\RouteStopStatus;
use App\Livewire\Routes\History;
use App\Models\Route;
use App\Models\RouteStop;
use Livewire\Livewire;

test('el administrador ve el historial de días de una ruta', function () {
    $route = Route::factory()->create();
    $day = makeRouteDay($route, '2026-09-10');
    RouteStop::factory()->for($day, 'route')->create(['status' => RouteStopStatus::Completed, 'delivered_quantity' => 500]);
    RouteStop::factory()->for($day, 'route')->create(['status' => RouteStopStatus::Failed]);

    $otro = makeRouteDay($route, '2026-09-09');

    Livewire::actingAs(makeUser('administrador'))
        ->test(History::class, ['route' => $route])
        ->assertOk()
        ->assertSee($day->route_date->format('d/m/Y'))
        ->assertSee($otro->route_date->format('d/m/Y'));
});

test('el historial filtra por rango de fechas', function () {
    $route = Route::factory()->create();
    makeRouteDay($route, '2026-09-01');
    makeRouteDay($route, '2026-09-10');

    Livewire::actingAs(makeUser('administrador'))
        ->test(History::class, ['route' => $route])
        ->set('from', '2026-09-05')
        ->assertDontSee('01/09/2026')
        ->assertSee('10/09/2026');
});

test('ver detalle de un día muestra sus paradas', function () {
    $route = Route::factory()->create();
    $day = makeRouteDay($route, '2026-09-10');
    RouteStop::factory()->for($day, 'route')->create(['customer_name' => 'Bar Central', 'status' => RouteStopStatus::Completed]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(History::class, ['route' => $route])
        ->call('viewDay', $day->id)
        ->assertSee('Bar Central');
});

test('un chofer no puede acceder al historial de rutas', function () {
    $route = Route::factory()->create();

    $this->actingAs(makeUser('chofer'))->get(route('routes.history', $route))->assertForbidden();
});
