<?php

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
    RouteStop::factory()->for($route)->create(['position' => 1, 'customer_name' => 'Uno', 'latitude' => 28.40, 'longitude' => -16.40]);
    RouteStop::factory()->for($route)->create(['position' => 2, 'customer_name' => 'Dos', 'latitude' => null, 'longitude' => null]);
    RouteStop::factory()->for($route)->create(['position' => 3, 'customer_name' => 'Tres', 'latitude' => 28.42, 'longitude' => -16.42]);

    $payload = app(RouteGeometry::class)->payloadFor($route);

    expect($payload['skipped'])->toBe(1)
        ->and($payload['stops'])->toHaveCount(2)
        ->and($payload['stops'][0])->toMatchArray(['n' => 1, 'name' => 'Uno', 'status' => 'pending'])
        ->and($payload['stops'][1]['n'])->toBe(3) // "Tres" mantiene su número real
        ->and($payload['meta']['distance_m'])->toBe(12500.0);
});
