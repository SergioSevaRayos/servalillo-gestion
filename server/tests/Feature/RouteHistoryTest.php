<?php

use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Livewire\Routes\History;
use App\Models\Device;
use App\Models\Driver;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\UnplannedStop;
use App\Models\User;
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

test('por defecto el historial muestra la fecha más reciente primero', function () {
    $route = Route::factory()->create();
    makeRouteDay($route, '2026-09-01');
    makeRouteDay($route, '2026-09-15');
    makeRouteDay($route, '2026-09-10');

    $component = Livewire::actingAs(makeUser('administrador'))
        ->test(History::class, ['route' => $route])
        ->assertSet('sort', 'route_date')
        ->assertSet('direction', 'desc');

    $html = $component->html();
    expect(strpos($html, '15/09/2026'))
        ->toBeLessThan(strpos($html, '10/09/2026'))
        ->and(strpos($html, '10/09/2026'))->toBeLessThan(strpos($html, '01/09/2026'));
});

test('la cabecera de fecha invierte el orden al pulsarla', function () {
    $route = Route::factory()->create();
    makeRouteDay($route, '2026-09-01');
    makeRouteDay($route, '2026-09-15');

    $component = Livewire::actingAs(makeUser('administrador'))
        ->test(History::class, ['route' => $route])
        ->call('sortBy', 'route_date')
        ->assertSet('sort', 'route_date')
        ->assertSet('direction', 'asc');

    $html = $component->html();
    expect(strpos($html, '01/09/2026'))->toBeLessThan(strpos($html, '15/09/2026'));
});

test('se puede ordenar el historial por estado', function () {
    $route = Route::factory()->create();
    makeRouteDay($route, '2026-09-01')->update(['status' => RouteStatus::Completed]);
    makeRouteDay($route, '2026-09-02')->update(['status' => RouteStatus::Published]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(History::class, ['route' => $route])
        ->call('sortBy', 'status')
        ->assertSet('sort', 'status')
        ->assertSet('direction', 'asc');
});

test('un chofer no puede acceder al historial de rutas', function () {
    $route = Route::factory()->create();

    $this->actingAs(makeUser('chofer'))->get(route('routes.history', $route))->assertForbidden();
});

test('ver detalle de un día muestra sus paradas no programadas', function () {
    $route = Route::factory()->create();
    $day = makeRouteDay($route, '2026-09-10');
    UnplannedStop::create([
        'route_id' => $day->id, 'latitude' => 28.40, 'longitude' => -16.40,
        'entered_at' => '2026-09-10 10:00:00', 'left_at' => '2026-09-10 10:12:00', 'seconds' => 720,
    ]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(History::class, ['route' => $route])
        ->call('viewDay', $day->id)
        ->assertSee(__('Paradas no programadas'))
        ->assertSee('12 min');
});

test('"Ver recorrido" del historial incluye las paradas no programadas del día', function () {
    config()->set('servalillo.routing.enabled', false);

    $route = Route::factory()->create();
    $day = makeRouteDay($route, '2026-09-10');
    UnplannedStop::create([
        'route_id' => $day->id, 'latitude' => 28.40, 'longitude' => -16.40,
        'entered_at' => '2026-09-10 10:00:00', 'left_at' => '2026-09-10 10:10:00', 'seconds' => 600,
    ]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(History::class, ['route' => $route])
        ->call('showDayMap', $day->id)
        ->assertDispatched('open-route-map', fn ($event, $params) => count($params['unplanned_stops']) === 1);
});

test('oficina cambia el estado de un día desde el historial', function () {
    $route = Route::factory()->create();
    $day = makeRouteDay($route, '2026-09-10');
    $day->update(['status' => RouteStatus::Completed, 'completed_at' => now(), 'liter_meter_start' => 500, 'liter_meter_end' => 700]);
    $day->truck->update(['liter_meter' => 700]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(History::class, ['route' => $route])
        ->call('setStatus', $day->id, 'cancelled')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect($day->fresh())
        ->status->toBe(RouteStatus::Cancelled)
        ->completed_at->toBeNull()
        ->liter_meter_end->toBeNull()
        ->and($day->fresh()->truck->liter_meter)->toBe(500);
});

test('oficina reasigna el chofer de un día y el dispositivo GPS le sigue', function () {
    $route = Route::factory()->create();
    $day = makeRouteDay($route, '2026-09-10');
    $originalDriverId = $day->driver_id;
    $device = Device::factory()->create(['driver_id' => $originalDriverId]);

    $newDriver = Driver::factory()->for(User::factory(), 'user')->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(History::class, ['route' => $route])
        ->call('openReassignModal', $day->id)
        ->set('reassignDriverId', $newDriver->id)
        ->set('reassignDeviceToo', true)
        ->call('reassignDriver')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect($day->fresh()->driver_id)->toBe($newDriver->id)
        ->and($device->fresh()->driver_id)->toBe($newDriver->id);
});

test('reasignar sin marcar el dispositivo deja el GPS tal cual', function () {
    $route = Route::factory()->create();
    $day = makeRouteDay($route, '2026-09-10');
    $originalDriverId = $day->driver_id;
    $device = Device::factory()->create(['driver_id' => $originalDriverId]);

    $newDriver = Driver::factory()->for(User::factory(), 'user')->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(History::class, ['route' => $route])
        ->call('openReassignModal', $day->id)
        ->set('reassignDriverId', $newDriver->id)
        ->set('reassignDeviceToo', false)
        ->call('reassignDriver')
        ->assertHasNoErrors();

    expect($day->fresh()->driver_id)->toBe($newDriver->id)
        ->and($device->fresh()->driver_id)->toBe($originalDriverId);
});

test('un chofer no puede reasignar el chofer de un día, ni siquiera el suyo', function () {
    $chofer = makeUser('chofer');
    $driver = Driver::factory()->create(['user_id' => $chofer->id]);
    $route = Route::factory()->create(['driver_id' => $driver->id]); // dueño de la ruta -> puede verla
    $day = makeRouteDay($route, '2026-09-10');

    Livewire::actingAs($chofer)
        ->test(History::class, ['route' => $route])
        ->call('openReassignModal', $day->id)
        ->assertForbidden();
});
