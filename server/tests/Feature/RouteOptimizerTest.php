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

it('usa la matriz de distancias reales de OSRM /table y reordena', function () {
    config()->set('servalillo.routing.enabled', true);

    // Orden actual: A(pos1), D(pos2), B(pos3), C(pos4)
    [$route, $s] = routeWithStops([
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 1], // A
        ['lat' => 28.46, 'lng' => -16.46, 'pos' => 2], // D
        ['lat' => 28.42, 'lng' => -16.42, 'pos' => 3], // B
        ['lat' => 28.44, 'lng' => -16.44, 'pos' => 4], // C
    ]);
    [$a, $d, $b, $c] = $s;

    // Matriz por carretera (m): A-B, B-C, C-D baratos; el resto caro => camino corto A,B,C,D
    Http::fake(['*/table/*' => Http::response([
        'code' => 'Ok',
        'distances' => [
            [0, 900, 100, 500],
            [900, 0, 500, 100],
            [100, 500, 0, 100],
            [500, 100, 100, 0],
        ],
    ])]);

    $result = app(RouteOptimizer::class)->optimize($route);

    expect($result['method'])->toBe('osrm')
        ->and($result['moved'])->toBeTrue()
        ->and(positionsOf($route))->toBe([$a->id, $b->id, $c->id, $d->id]);

    Http::assertSent(fn ($req) => str_contains($req->url(), '/table/v1/driving/')
        && str_contains($req->url(), 'annotations=distance')
        && str_contains($req->url(), '-16.4,28.4')); // lon,lat, no lat,lon
});

it('optimización libre: encuentra el camino más corto aunque cambie la primera parada', function () {
    config()->set('servalillo.routing.enabled', true);

    // Orden actual: M(pos1, en medio), A(pos2, un extremo), Z(pos3, otro extremo)
    [$route, $s] = routeWithStops([
        ['lat' => 28.44, 'lng' => -16.44, 'pos' => 1], // M
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 2], // A
        ['lat' => 28.48, 'lng' => -16.48, 'pos' => 3], // Z
    ]);
    [$m, $a, $z] = $s;

    // M-A y M-Z baratos, A-Z carísimo => el camino corto es A,M,Z (o Z,M,A)
    Http::fake(['*/table/*' => Http::response([
        'code' => 'Ok',
        'distances' => [[0, 100, 100], [100, 0, 9000], [100, 9000, 0]],
    ])]);

    app(RouteOptimizer::class)->optimize($route);

    // Ya no empieza por M (que estaba primera): pasa a un extremo.
    expect(positionsOf($route))->toBeIn([[$a->id, $m->id, $z->id], [$z->id, $m->id, $a->id]]);
});

it('no reordena si con la matriz real el orden actual ya es igual o mejor', function () {
    config()->set('servalillo.routing.enabled', true);

    [$route, $s] = routeWithStops([
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 1],
        ['lat' => 28.42, 'lng' => -16.42, 'pos' => 2],
        ['lat' => 28.44, 'lng' => -16.44, 'pos' => 3],
    ]);
    $original = positionsOf($route);

    // El orden actual (0,1,2) ya es el más barato por carretera.
    Http::fake(['*/table/*' => Http::response([
        'code' => 'Ok',
        'distances' => [[0, 100, 900], [100, 0, 100], [900, 100, 0]],
    ])]);

    $result = app(RouteOptimizer::class)->optimize($route);

    expect($result['moved'])->toBeFalse()
        ->and(positionsOf($route))->toBe($original);
});

it('cae a la heurística local (haversine) si OSRM no responde', function () {
    config()->set('servalillo.routing.enabled', true);
    Http::fake(['*' => Http::response('nope', 503)]);

    // Paradas casi en línea; el orden actual (A, lejos, ...) es un zigzag.
    [$route, $s] = routeWithStops([
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 1], // A
        ['lat' => 28.46, 'lng' => -16.46, 'pos' => 2], // lejos
        ['lat' => 28.42, 'lng' => -16.42, 'pos' => 3], // cerca de A
        ['lat' => 28.44, 'lng' => -16.44, 'pos' => 4],
    ]);
    [$a, $far, $near, $mid] = $s;

    $result = app(RouteOptimizer::class)->optimize($route);

    expect($result['method'])->toBe('local')
        ->and($result['moved'])->toBeTrue()
        ->and($result['distance_after_m'])->toBeLessThan($result['distance_before_m'])
        ->and(positionsOf($route))->toBeIn([
            [$a->id, $near->id, $mid->id, $far->id],   // monótono ascendente
            [$far->id, $mid->id, $near->id, $a->id],   // o el mismo camino al revés
        ]);
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

it('optimiza las pendientes desde la última parada cerrada', function () {
    [$route, $s] = routeWithStops([
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 1, 'status' => RouteStopStatus::Completed], // camión aquí
        ['lat' => 28.41, 'lng' => -16.41, 'pos' => 2], // la más cercana a la cerrada
        ['lat' => 28.46, 'lng' => -16.46, 'pos' => 3], // la más lejana
        ['lat' => 28.43, 'lng' => -16.43, 'pos' => 4], // intermedia
    ]);
    [$done, $near, $far, $mid] = $s;

    app(RouteOptimizer::class)->optimize($route);

    // La cerrada se queda primera; las pendientes salen desde ella: cerca -> media -> lejos.
    expect(positionsOf($route))->toBe([$done->id, $near->id, $mid->id, $far->id])
        ->and($done->fresh()->position)->toBe(1)
        ->and($done->fresh()->status)->toBe(RouteStopStatus::Completed);
});

it('optimiza una ruta con una completada intermedia y hueco de posiciones sin dar error', function () {
    [$route, $s] = routeWithStops([
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 1, 'status' => RouteStopStatus::Completed],
        ['lat' => 28.46, 'lng' => -16.46, 'pos' => 2],
        ['lat' => 28.42, 'lng' => -16.42, 'pos' => 5], // hueco (posición borrada en medio)
        ['lat' => 28.44, 'lng' => -16.44, 'pos' => 8],
    ]);
    [$done] = $s;

    $result = app(RouteOptimizer::class)->optimize($route);

    // No lanza 422; la completada se queda primera y la numeración se compacta a 1..4.
    expect($done->fresh()->position)->toBe(1)
        ->and($done->fresh()->status)->toBe(RouteStopStatus::Completed)
        ->and($route->stops()->pluck('position')->all())->toBe([1, 2, 3, 4])
        ->and($result['moved'])->toBeTrue();
});

it('optimiza desde un origen explícito (base o parada elegida)', function () {
    [$route, $s] = routeWithStops([
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 1], // A
        ['lat' => 28.48, 'lng' => -16.48, 'pos' => 2], // Z
        ['lat' => 28.44, 'lng' => -16.44, 'pos' => 3], // M (en medio)
    ]);
    [$a, $z, $m] = $s;

    // Origen pegado a Z: el recorrido más corto sale de Z -> M -> A.
    app(RouteOptimizer::class)->optimize($route, [28.485, -16.485]);

    expect(positionsOf($route))->toBe([$z->id, $m->id, $a->id]);
});

it('el origen explícito manda sobre la última parada cerrada', function () {
    [$route, $s] = routeWithStops([
        ['lat' => 28.40, 'lng' => -16.40, 'pos' => 1, 'status' => RouteStopStatus::Completed], // cerrada al oeste
        ['lat' => 28.44, 'lng' => -16.44, 'pos' => 2],
        ['lat' => 28.48, 'lng' => -16.48, 'pos' => 3],
    ]);
    [$done, $mid, $east] = $s;

    // Aunque haya una cerrada, se pasa un origen al este: la primera pendiente será la del este.
    app(RouteOptimizer::class)->optimize($route, [28.49, -16.49]);

    expect(positionsOf($route))->toBe([$done->id, $east->id, $mid->id])
        ->and($done->fresh()->position)->toBe(1);
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
