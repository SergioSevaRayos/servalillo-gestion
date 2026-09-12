<?php

use App\Enums\RouteStopStatus;
use App\Models\Device;
use App\Models\GpsPosition;
use App\Models\RouteStop;
use App\Services\RouteGeometry;
use Illuminate\Support\Facades\Http;

function osrmRouteOk(): array
{
    return [
        'code' => 'Ok',
        'routes' => [[
            'distance' => 12500.0,
            'duration' => 1800.0,
            'geometry' => ['coordinates' => [[-16.40, 28.40], [-16.41, 28.41], [-16.42, 28.42]]],
        ]],
    ];
}

it('pide a OSRM la geometría y la devuelve como [lat, lon] + distancia y duración', function () {
    config()->set('servalillo.routing.enabled', true);
    Http::fake(['*/route/*' => Http::response(osrmRouteOk())]);

    $geo = app(RouteGeometry::class)->for([[28.40, -16.40], [28.42, -16.42]]);

    expect($geo['distance_m'])->toBe(12500.0)
        ->and($geo['duration_s'])->toBe(1800.0)
        ->and($geo['line'][0])->toBe([28.40, -16.40]); // [lat, lon], no [lon, lat]

    Http::assertSent(fn ($req) => str_contains($req->url(), '/route/v1/driving/')
        && str_contains($req->url(), 'geometries=geojson'));
});

it('devuelve null si OSRM no responde', function () {
    config()->set('servalillo.routing.enabled', true);
    Http::fake(['*' => Http::response('boom', 500)]);

    expect(app(RouteGeometry::class)->for([[28.40, -16.40], [28.42, -16.42]]))->toBeNull();
});

it('devuelve null si el motor está desactivado o hay menos de 2 puntos', function () {
    config()->set('servalillo.routing.enabled', false);
    expect(app(RouteGeometry::class)->for([[28.40, -16.40], [28.42, -16.42]]))->toBeNull();

    config()->set('servalillo.routing.enabled', true);
    Http::fake();
    expect(app(RouteGeometry::class)->for([[28.40, -16.40]]))->toBeNull();
});

it('payloadFor numera las paradas por su posición y aparta las que no tienen coordenadas', function () {
    config()->set('servalillo.routing.enabled', true);
    Http::fake(['*/route/*' => Http::response(osrmRouteOk())]);

    $route = makeRoute('2026-09-10');
    RouteStop::factory()->for($route, 'route')->create(['position' => 1, 'customer_name' => 'Uno', 'latitude' => 28.40, 'longitude' => -16.40]);
    RouteStop::factory()->for($route, 'route')->create(['position' => 2, 'customer_name' => 'Dos', 'latitude' => null, 'longitude' => null]);
    RouteStop::factory()->for($route, 'route')->create(['position' => 3, 'customer_name' => 'Tres', 'latitude' => 28.42, 'longitude' => -16.42]);

    $payload = app(RouteGeometry::class)->payloadFor($route);

    expect($payload['skipped'])->toBe(1)
        ->and($payload['stops'])->toHaveCount(2)
        ->and($payload['stops'][0])->toMatchArray(['n' => 1, 'name' => 'Uno', 'status' => 'pending', 'status_label' => RouteStopStatus::Pending->label(), 'rescheduled' => false])
        ->and($payload['stops'][1]['n'])->toBe(3) // "Tres" mantiene su número real
        ->and($payload['meta']['distance_m'])->toBe(12500.0)
        ->and($payload['vehicle'])->toBeNull(); // sin posiciones GPS
});

it('payloadFor marca "rescheduled" a partir del texto que deja StopActionForm en failure_reason', function () {
    $route = makeRoute('2026-09-10');
    RouteStop::factory()->for($route, 'route')->create([
        'position' => 1, 'latitude' => 28.40, 'longitude' => -16.40,
        'status' => RouteStopStatus::Failed, 'failure_reason' => 'No había nadie · Reprogramada para 20/09/2026',
    ]);
    RouteStop::factory()->for($route, 'route')->create([
        'position' => 2, 'latitude' => 28.41, 'longitude' => -16.41,
        'status' => RouteStopStatus::Failed, 'failure_reason' => 'No había nadie',
    ]);

    $payload = app(RouteGeometry::class)->payloadFor($route);

    expect($payload['stops'][0]['rescheduled'])->toBeTrue()
        ->and($payload['stops'][1]['rescheduled'])->toBeFalse();
});

it('payloadFor añade el camión y el trazado hasta la primera parada si hay posición GPS', function () {
    config()->set('servalillo.routing.enabled', true);
    Http::fake(['*/route/*' => Http::response(osrmRouteOk())]);

    $route = makeRoute('2026-09-10');
    RouteStop::factory()->for($route, 'route')->create(['position' => 1, 'customer_name' => 'Primera', 'latitude' => 28.40, 'longitude' => -16.40]);
    RouteStop::factory()->for($route, 'route')->create(['position' => 2, 'customer_name' => 'Segunda', 'latitude' => 28.42, 'longitude' => -16.42]);

    $device = Device::factory()->create();
    GpsPosition::insert([
        ['device_id' => $device->id, 'route_id' => $route->id, 'driver_id' => $route->driver_id, 'latitude' => 28.35, 'longitude' => -16.35, 'accuracy_m' => 12.0, 'recorded_at' => now()->subMinutes(2), 'created_at' => now()],
    ]);

    $payload = app(RouteGeometry::class)->payloadFor($route);

    expect($payload['vehicle'])->not->toBeNull()
        ->and($payload['vehicle']['lat'])->toBe(28.35)
        ->and($payload['vehicle']['next_stop'])->toMatchArray(['n' => 1, 'name' => 'Primera'])
        ->and($payload['vehicle']['approach']['distance_m'])->toBe(12500.0)
        ->and($payload['vehicle']['approach']['line'][0])->toBe([28.40, -16.40]);
});

it('el camión se ve en el mapa aunque la posición GPS sea de otro día distinto al que se está viendo', function () {
    config()->set('servalillo.routing.enabled', true);
    Http::fake(['*/route/*' => Http::response(osrmRouteOk())]);

    $route = makeRoute('2026-09-15'); // día futuro, sin actividad registrada todavía ese día
    RouteStop::factory()->for($route, 'route')->create(['position' => 1, 'customer_name' => 'Primera', 'latitude' => 28.40, 'longitude' => -16.40]);

    $device = Device::factory()->create();
    GpsPosition::insert([
        // posición real de "ahora" (hoy), no del día 15 que se está mirando en el tablero
        ['device_id' => $device->id, 'route_id' => null, 'driver_id' => $route->driver_id, 'latitude' => 28.35, 'longitude' => -16.35, 'accuracy_m' => 12.0, 'recorded_at' => now(), 'created_at' => now()],
    ]);

    $payload = app(RouteGeometry::class)->payloadFor($route);

    expect($payload['vehicle'])->not->toBeNull()
        ->and($payload['vehicle']['lat'])->toBe(28.35);
});

it('el trazado del camión apunta a la primera parada PENDIENTE, no a una ya cerrada', function () {
    config()->set('servalillo.routing.enabled', true);
    Http::fake(['*/route/*' => Http::response(osrmRouteOk())]);

    $route = makeRoute('2026-09-10');
    RouteStop::factory()->for($route, 'route')->create(['position' => 1, 'customer_name' => 'Ya hecha', 'latitude' => 28.40, 'longitude' => -16.40, 'status' => RouteStopStatus::Completed]);
    RouteStop::factory()->for($route, 'route')->create(['position' => 2, 'customer_name' => 'Siguiente', 'latitude' => 28.42, 'longitude' => -16.42]);

    $device = Device::factory()->create();
    GpsPosition::insert([
        ['device_id' => $device->id, 'route_id' => $route->id, 'driver_id' => $route->driver_id, 'latitude' => 28.41, 'longitude' => -16.41, 'accuracy_m' => null, 'recorded_at' => now(), 'created_at' => now()],
    ]);

    $payload = app(RouteGeometry::class)->payloadFor($route);

    expect($payload['vehicle']['next_stop'])->toMatchArray(['n' => 2, 'name' => 'Siguiente']);
});
