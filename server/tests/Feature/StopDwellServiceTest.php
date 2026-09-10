<?php

use App\Models\RouteStop;
use App\Models\StopVisit;
use App\Services\StopDwellService;
use App\Support\Haversine;
use Illuminate\Support\Carbon;

/*
| Tiempo de permanencia del camión en cada parada (geocerca por GPS). El track se construye con
| gpsTrack() / metersOffset() (helpers en tests/Pest.php). El "ahora" se fija con Carbon::setTestNow
| a bastante después del track para que las visitas salgan CERRADAS (con `seconds`), salvo en el
| test de la visita abierta.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-03-02 20:00:00'));
    config()->set('servalillo.dwell.radius_meters', 100);
    config()->set('servalillo.dwell.min_seconds', 120);
    config()->set('servalillo.dwell.merge_gap_seconds', 180);
    config()->set('servalillo.dwell.max_gap_seconds', 600);
    config()->set('servalillo.dwell.clamp_to_shift', false);

    // Lejos de la base (Almería) para que el filtro exclude_base no toque nada.
    $this->stopLat = 28.4500;
    $this->stopLng = -16.3000;
});

afterEach(fn () => Carbon::setTestNow());

/** Crea un día de ruta con una parada en (stopLat, stopLng). */
function dwellDay(float $lat, float $lng): RouteStop
{
    $day = makeRoute('2026-03-02');

    return RouteStop::factory()->for($day, 'route')->create([
        'position' => 1,
        'latitude' => $lat,
        'longitude' => $lng,
    ]);
}

/**
 * Serie densa de fixes en el mismo punto, cada `$every` segundos entre `$from` y `$to`
 * (ambos 'HH:MM:SS' del 2026-03-02).
 *
 * @param  array{0: float, 1: float}  $point
 */
function denseRun(array $point, string $from, string $to, int $every = 45): array
{
    $t = Carbon::parse("2026-03-02 {$from}");
    $end = Carbon::parse("2026-03-02 {$to}");
    $out = [];

    while ($t->lte($end)) {
        $out[] = [$point[0], $point[1], $t->format('Y-m-d H:i:s')];
        $t = $t->copy()->addSeconds($every);
    }

    return $out;
}

it('cuenta el tiempo con fixes dentro del radio', function () {
    $stop = dwellDay($this->stopLat, $this->stopLng);
    [$lat, $lng] = metersOffset($this->stopLat, $this->stopLng, 40, 0); // 40 m al norte

    // 10 fixes cada 45 s => ~405 s dentro.
    $fixes = [];
    for ($i = 0; $i < 10; $i++) {
        $fixes[] = [$lat, $lng, Carbon::parse('2026-03-02 10:00:00')->addSeconds(45 * $i)->format('Y-m-d H:i:s')];
    }
    gpsTrack($stop->route, $fixes);

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    $visit = StopVisit::where('route_stop_id', $stop->id)->sole();
    expect($visit->seconds)->toBe(405)
        ->and($visit->left_at)->not->toBeNull()
        ->and($stop->fresh()->onSiteSeconds())->toBe(405);
});

it('ignora los fixes fuera del radio', function () {
    $stop = dwellDay($this->stopLat, $this->stopLng);
    [$lat, $lng] = metersOffset($this->stopLat, $this->stopLng, 300, 0);

    gpsTrack($stop->route, collect(range(0, 9))->map(fn ($i) => [
        $lat, $lng, Carbon::parse('2026-03-02 10:00:00')->addSeconds(45 * $i)->format('Y-m-d H:i:s'),
    ])->all());

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    expect(StopVisit::where('route_stop_id', $stop->id)->count())->toBe(0);
});

it('descarta visitas de menos de min_seconds (el camión solo pasa cerca)', function () {
    $stop = dwellDay($this->stopLat, $this->stopLng);
    [$lat, $lng] = metersOffset($this->stopLat, $this->stopLng, 30, 0);

    gpsTrack($stop->route, [
        [$lat, $lng, '2026-03-02 10:00:00'],
        [$lat, $lng, '2026-03-02 10:00:30'], // 30 s < 120
    ]);

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    expect(StopVisit::where('route_stop_id', $stop->id)->count())->toBe(0);
});

it('parte en dos visitas cuando el hueco supera merge_gap_seconds', function () {
    $stop = dwellDay($this->stopLat, $this->stopLng);
    $point = metersOffset($this->stopLat, $this->stopLng, 20, 0);

    gpsTrack($stop->route, array_merge(
        denseRun($point, '10:00:00', '10:03:00'), // visita 1: 180 s
        denseRun($point, '10:18:00', '10:21:00'), // visita 2: 180 s (hueco de 15 min entre ambas)
    ));

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    $visits = StopVisit::where('route_stop_id', $stop->id)->orderBy('entered_at')->get();
    expect($visits)->toHaveCount(2)
        ->and($visits[0]->seconds)->toBe(180)
        ->and($visits[1]->seconds)->toBe(180)
        ->and($stop->fresh()->onSiteSeconds())->toBe(360); // suma, sin el hueco
});

it('no infla la duración cuando hay un apagón GPS dentro de la visita', function () {
    config()->set('servalillo.dwell.merge_gap_seconds', 3600); // no partir
    config()->set('servalillo.dwell.max_gap_seconds', 300);

    $stop = dwellDay($this->stopLat, $this->stopLng);
    [$lat, $lng] = metersOffset($this->stopLat, $this->stopLng, 20, 0);

    gpsTrack($stop->route, [
        [$lat, $lng, '2026-03-02 10:00:00'],
        [$lat, $lng, '2026-03-02 10:10:00'], // hueco de 600 s, topado a 300
    ]);

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    expect(StopVisit::where('route_stop_id', $stop->id)->sole()->seconds)->toBe(300);
});

it('atribuye cada posición a la parada más cercana con geocercas solapadas', function () {
    $day = makeRoute('2026-03-02');
    $a = RouteStop::factory()->for($day, 'route')->create(['position' => 1, 'latitude' => $this->stopLat, 'longitude' => $this->stopLng]);
    [$bLat, $bLng] = metersOffset($this->stopLat, $this->stopLng, 0, 120); // B a 120 m de A
    $b = RouteStop::factory()->for($day, 'route')->create(['position' => 2, 'latitude' => $bLat, 'longitude' => $bLng]);

    [$lat, $lng] = metersOffset($this->stopLat, $this->stopLng, 0, 40); // 40 m de A, ~80 m de B
    gpsTrack($day, collect(range(0, 8))->map(fn ($i) => [
        $lat, $lng, Carbon::parse('2026-03-02 10:00:00')->addSeconds(45 * $i)->format('Y-m-d H:i:s'),
    ])->all());

    app(StopDwellService::class)->recomputeForRouteDay($day);

    expect(StopVisit::where('route_stop_id', $a->id)->count())->toBe(1)
        ->and(StopVisit::where('route_stop_id', $b->id)->count())->toBe(0);
});

it('excluye el tiempo aparcado en la base', function () {
    $baseLat = (float) config('servalillo.base.latitude');
    $baseLng = (float) config('servalillo.base.longitude');

    // Parada pegada a la base.
    [$sLat, $sLng] = metersOffset($baseLat, $baseLng, 60, 0);
    $stop = dwellDay($sLat, $sLng);

    // El camión aparcado justo en la base durante una hora.
    gpsTrack($stop->route, collect(range(0, 20))->map(fn ($i) => [
        $baseLat, $baseLng, Carbon::parse('2026-03-02 10:00:00')->addMinutes(3 * $i)->format('Y-m-d H:i:s'),
    ])->all());

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    expect(StopVisit::where('route_stop_id', $stop->id)->count())->toBe(0);
});

it('rechaza los fixes con precisión mala', function () {
    $stop = dwellDay($this->stopLat, $this->stopLng);
    [$lat, $lng] = metersOffset($this->stopLat, $this->stopLng, 20, 0);

    gpsTrack($stop->route, collect(range(0, 9))->map(fn ($i) => [
        $lat, $lng, Carbon::parse('2026-03-02 10:00:00')->addSeconds(45 * $i)->format('Y-m-d H:i:s'), 400, // accuracy_m
    ])->all());

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    expect(StopVisit::where('route_stop_id', $stop->id)->count())->toBe(0);
});

it('registra dos visitas si el camión vuelve más tarde', function () {
    $stop = dwellDay($this->stopLat, $this->stopLng);
    [$lat, $lng] = metersOffset($this->stopLat, $this->stopLng, 20, 0);

    gpsTrack($stop->route, array_merge(
        denseRun([$lat, $lng], '09:00:00', '09:05:00'),
        denseRun([$lat, $lng], '13:00:00', '13:04:00'),
    ));

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    $visits = StopVisit::where('route_stop_id', $stop->id)->orderBy('entered_at')->get();
    expect($visits)->toHaveCount(2)
        ->and($visits[0]->entered_at->format('H:i'))->toBe('09:00')
        ->and($visits[1]->entered_at->format('H:i'))->toBe('13:00');
});

it('deja la visita abierta si el último fix sigue dentro del radio', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-02 10:16:00')); // 60 s tras el último fix
    $stop = dwellDay($this->stopLat, $this->stopLng);
    [$lat, $lng] = metersOffset($this->stopLat, $this->stopLng, 20, 0);

    gpsTrack($stop->route, collect(range(0, 20))->map(fn ($i) => [
        $lat, $lng, Carbon::parse('2026-03-02 10:00:00')->addSeconds(45 * $i)->format('Y-m-d H:i:s'),
    ])->all());

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    $visit = StopVisit::where('route_stop_id', $stop->id)->sole();
    expect($visit->left_at)->toBeNull()
        ->and($visit->seconds)->toBeNull()
        ->and($stop->fresh()->isOnSiteNow())->toBeTrue()
        ->and($stop->fresh()->onSiteSeconds())->toBeGreaterThan(0);
});

it('es idempotente', function () {
    $stop = dwellDay($this->stopLat, $this->stopLng);
    [$lat, $lng] = metersOffset($this->stopLat, $this->stopLng, 20, 0);
    gpsTrack($stop->route, collect(range(0, 9))->map(fn ($i) => [
        $lat, $lng, Carbon::parse('2026-03-02 10:00:00')->addSeconds(45 * $i)->format('Y-m-d H:i:s'),
    ])->all());

    $svc = app(StopDwellService::class);
    $svc->recomputeForRouteDay($stop->route);
    $first = StopVisit::where('route_stop_id', $stop->id)->sole();

    $svc->recomputeForRouteDay($stop->route->fresh());
    $rows = StopVisit::where('route_stop_id', $stop->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->seconds)->toBe($first->seconds);
});

it('da el mismo resultado con un lote de GPS desordenado', function () {
    $stop = dwellDay($this->stopLat, $this->stopLng);
    [$lat, $lng] = metersOffset($this->stopLat, $this->stopLng, 20, 0);

    $times = collect(range(0, 9))
        ->map(fn ($i) => Carbon::parse('2026-03-02 10:00:00')->addSeconds(45 * $i)->format('Y-m-d H:i:s'))
        ->shuffle();

    gpsTrack($stop->route, $times->map(fn ($t) => [$lat, $lng, $t])->all());

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    expect(StopVisit::where('route_stop_id', $stop->id)->sole()->seconds)->toBe(405);
});

it('ignora las paradas sin coordenadas', function () {
    $day = makeRoute('2026-03-02');
    RouteStop::factory()->for($day, 'route')->create(['position' => 1, 'latitude' => null, 'longitude' => null]);
    [$lat, $lng] = metersOffset($this->stopLat, $this->stopLng, 20, 0);
    gpsTrack($day, [[$lat, $lng, '2026-03-02 10:00:00'], [$lat, $lng, '2026-03-02 10:05:00']]);

    $written = app(StopDwellService::class)->recomputeForRouteDay($day);

    expect($written)->toBe(0)
        ->and(StopVisit::count())->toBe(0);
});

it('respeta el horario de jornada cuando clamp_to_shift está activo', function () {
    config()->set('servalillo.dwell.clamp_to_shift', true);

    $stop = dwellDay($this->stopLat, $this->stopLng);
    $stop->route->update(['started_at' => '2026-03-02 08:00:00', 'completed_at' => '2026-03-02 14:00:00']);
    $point = metersOffset($this->stopLat, $this->stopLng, 20, 0);

    gpsTrack($stop->route, array_merge(
        denseRun($point, '06:00:00', '06:10:00'), // antes de la jornada (- 30 min): fuera
        denseRun($point, '10:00:00', '10:05:00'), // dentro
    ));

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    $visit = StopVisit::where('route_stop_id', $stop->id)->sole();
    expect($visit->entered_at->format('H:i'))->toBe('10:00')
        ->and($visit->seconds)->toBe(270); // 7 fixes 45 s => 6 huecos

});

it('metersOffset y Haversine concuerdan', function () {
    [$lat, $lng] = metersOffset($this->stopLat, $this->stopLng, 60, 80); // 100 m en diagonal
    expect(Haversine::meters($this->stopLat, $this->stopLng, $lat, $lng))->toBeGreaterThan(95)->toBeLessThan(105);
});
