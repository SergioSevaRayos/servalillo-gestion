<?php

use App\Enums\RouteStopStatus;
use App\Models\Route;
use App\Models\RouteStop;
use App\Services\RouteOptimizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use OwenIt\Auditing\Models\Audit;

/**
 * Monta una ruta con paradas en coordenadas/posiciones/estados concretos.
 *
 * @param  list<array{lat: float|null, lng: float|null, pos: int, status?: RouteStopStatus}>  $stops
 * @return array{0: Route, 1: Collection<int, RouteStop>}
 */
function routeWithStops(array $stops): array
{
    $route = makeRoute('2026-09-10');

    $models = collect($stops)->map(fn (array $s) => RouteStop::factory()->for($route)->create([
        'latitude' => $s['lat'],
        'longitude' => $s['lng'],
        'position' => $s['pos'],
        'status' => $s['status'] ?? RouteStopStatus::Pending,
    ]));

    return [$route, $models];
}

/** IDs de las paradas de la ruta en orden de position. */
function positionsOf(Route $route): array
{
    return $route->stops()->pluck('id')->all();
}

it('usa OSRM y reordena según waypoint_index', function () {
    config()->set('servalillo.routing.enabled', true);

    // Orden actual: A(pos1), D(pos2), B(pos3), C(pos4)
    [$route, $s] = routeWithStops([
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 1], // A (ancla)
        ['lat' => 28.46, 'lng' => -16.46, 'pos' => 2], // D
        ['lat' => 28.42, 'lng' => -16.42, 'pos' => 3], // B
        ['lat' => 28.44, 'lng' => -16.44, 'pos' => 4], // C
    ]);
    [$a, $d, $b, $c] = $s;

    // OSRM: input [A,D,B,C] -> slots [0,3,1,2] => orden óptimo A,B,C,D
    Http::fake(['*/trip/*' => Http::response([
        'code' => 'Ok',
        'waypoints' => [
            ['waypoint_index' => 0],
            ['waypoint_index' => 3],
            ['waypoint_index' => 1],
            ['waypoint_index' => 2],
        ],
        'trips' => [['distance' => 12345.6]],
    ])]);

    $result = app(RouteOptimizer::class)->optimize($route);

    expect($result['method'])->toBe('osrm')
        ->and($result['moved'])->toBeTrue()
        ->and(positionsOf($route))->toBe([$a->id, $b->id, $c->id, $d->id]);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'source=first')
        && str_contains($req->url(), 'roundtrip=true')
        && str_contains($req->url(), '-16.4,28.4')); // lon,lat, no lat,lon
});

it('cae a la heurística local si OSRM no responde, dejando fija la primera parada', function () {
    config()->set('servalillo.routing.enabled', true);
    Http::fake(['*' => Http::response('nope', 503)]);

    [$route, $s] = routeWithStops([
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 1], // A (ancla)
        ['lat' => 28.46, 'lng' => -16.46, 'pos' => 2], // lejos
        ['lat' => 28.42, 'lng' => -16.42, 'pos' => 3], // cerca de A
        ['lat' => 28.44, 'lng' => -16.44, 'pos' => 4],
    ]);
    [$a, $far, $near, $mid] = $s;

    $result = app(RouteOptimizer::class)->optimize($route);

    expect($result['method'])->toBe('local')
        ->and($result['moved'])->toBeTrue()
        ->and(positionsOf($route))->toBe([$a->id, $near->id, $mid->id, $far->id])
        ->and($result['distance_after_m'])->toBeLessThanOrEqual($result['distance_before_m']);
});

it('no toca nada si la ruta ya está en el mejor orden', function () {
    [$route, $s] = routeWithStops([
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 1],
        ['lat' => 28.41, 'lng' => -16.41, 'pos' => 2],
        ['lat' => 28.42, 'lng' => -16.42, 'pos' => 3],
        ['lat' => 28.43, 'lng' => -16.43, 'pos' => 4],
    ]);

    $auditsBefore = Audit::count();
    $result = app(RouteOptimizer::class)->optimize($route);

    expect($result['moved'])->toBeFalse()
        ->and($result['reordered'])->toBe(0)
        ->and($result['method'])->toBe('local')
        ->and(Audit::count())->toBe($auditsBefore);
});

it('deja al final las paradas pendientes sin coordenadas', function () {
    [$route, $s] = routeWithStops([
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 1],
        ['lat' => 28.46, 'lng' => -16.46, 'pos' => 2],
        ['lat' => 28.42, 'lng' => -16.42, 'pos' => 3],
        ['lat' => null, 'lng' => null, 'pos' => 4],  // sin coords
        ['lat' => null, 'lng' => null, 'pos' => 5],  // sin coords
    ]);
    [, , , $noCoordsA, $noCoordsB] = $s;

    $result = app(RouteOptimizer::class)->optimize($route);

    expect($result['optimizable'])->toBe(3)
        ->and($result['skipped_no_coords'])->toBe(2);

    $order = positionsOf($route);
    expect(array_slice($order, -2))->toBe([$noCoordsA->id, $noCoordsB->id]);
});

it('no hace nada si hay menos de 2 paradas con coordenadas', function () {
    [$route, $s] = routeWithStops([
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 1],
        ['lat' => null, 'lng' => null, 'pos' => 2],
        ['lat' => null, 'lng' => null, 'pos' => 3],
    ]);
    $original = positionsOf($route);

    $result = app(RouteOptimizer::class)->optimize($route);

    expect($result['method'])->toBe('none')
        ->and($result['moved'])->toBeFalse()
        ->and($result['optimizable'])->toBe(1)
        ->and($result['skipped_no_coords'])->toBe(2)
        ->and(positionsOf($route))->toBe($original);
});

it('respeta el sitio de las paradas ya cerradas', function () {
    [$route, $s] = routeWithStops([
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 1, 'status' => RouteStopStatus::Completed],
        ['lat' => 28.41, 'lng' => -16.41, 'pos' => 2], // ancla pendiente
        ['lat' => 28.46, 'lng' => -16.46, 'pos' => 3],
        ['lat' => 28.43, 'lng' => -16.43, 'pos' => 4],
    ]);
    [$done, $anchor, $far, $mid] = $s;

    app(RouteOptimizer::class)->optimize($route);

    $order = positionsOf($route);
    expect($order[0])->toBe($done->id)            // cerrada: sigue primera
        ->and($order[1])->toBe($anchor->id)       // ancla pendiente: sigue segunda
        ->and($order)->toBe([$done->id, $anchor->id, $mid->id, $far->id]);
    expect($done->fresh()->position)->toBe(1);
});

it('no revienta con lat/lon como string (cast decimal:7) y devuelve distancias float', function () {
    [$route] = routeWithStops([
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 1],
        ['lat' => 28.46, 'lng' => -16.46, 'pos' => 2],
        ['lat' => 28.42, 'lng' => -16.42, 'pos' => 3],
    ]);

    $result = app(RouteOptimizer::class)->optimize($route);

    expect($result['distance_before_m'])->toBeFloat()
        ->and($result['distance_after_m'])->toBeFloat();
});
