<?php

use App\Enums\RouteStopStatus;
use App\Models\RouteStop;
use App\Models\StopVisit;
use App\Models\UnplannedStop;
use App\Notifications\UnplannedStopDetected;
use App\Services\StopDwellService;
use App\Support\Haversine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

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

// --- Pérdida de señal GPS durante una parada ("sin cobertura") ---------------------------------
//
// La app del móvil NO deja de capturar posiciones por falta de cobertura de RED: el GPS funciona
// sin datos móviles, cada fix se encola en local y se manda más tarde con su hora real de captura
// (`recorded_at`) en cuanto vuelve la conexión — StopDwellService funciona por "replay" y no le
// importa cuándo llegó el dato, solo `recorded_at`, así que la pérdida de cobertura de RED nunca
// genera un hueco real (ver el test de "lote de recuperación" más abajo). El hueco real viene de
// perder la SEÑAL GPS (nave cubierta, interior, timeout del GPS del móvil): ahí sí no hay fix que
// guardar, y es lo que gobierna `merge_gap_seconds`.

it('puentea un hueco de señal GPS por debajo del umbral: el camión sigue contando como si hubiera seguido ahí', function () {
    config()->set('servalillo.dwell.merge_gap_seconds', 600); // valor real de producción

    $stop = dwellDay($this->stopLat, $this->stopLng);
    [$lat, $lng] = metersOffset($this->stopLat, $this->stopLng, 20, 0);

    gpsTrack($stop->route, [
        [$lat, $lng, '2026-03-02 10:00:00'],
        [$lat, $lng, '2026-03-02 10:00:45'],
        // el móvil pierde señal GPS ~5 min (p. ej. dentro de una nave) — ningún fix en medio.
        [$lat, $lng, '2026-03-02 10:05:45'],
        [$lat, $lng, '2026-03-02 10:06:30'],
    ]);

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    $visit = StopVisit::where('route_stop_id', $stop->id)->sole();
    expect($visit->entered_at->format('H:i:s'))->toBe('10:00:00')
        ->and($visit->left_at->format('H:i:s'))->toBe('10:06:30')
        ->and($visit->seconds)->toBe(390); // 6 min 30 s completos, hueco incluido — no se pierde
});

it('corta la visita cuando el hueco de señal GPS supera el umbral (ya no se asume que siguiera parado)', function () {
    config()->set('servalillo.dwell.merge_gap_seconds', 600);

    $stop = dwellDay($this->stopLat, $this->stopLng);
    $point = metersOffset($this->stopLat, $this->stopLng, 20, 0);

    gpsTrack($stop->route, array_merge(
        denseRun($point, '10:00:00', '10:03:00'),
        denseRun($point, '10:18:00', '10:21:00'), // hueco de 15 min > 600 s
    ));

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    expect(StopVisit::where('route_stop_id', $stop->id)->count())->toBe(2);
});

it('el límite exacto de merge_gap_seconds no parte la visita; superarlo en 1 s sí', function () {
    config()->set('servalillo.dwell.merge_gap_seconds', 600);
    $point = metersOffset($this->stopLat, $this->stopLng, 20, 0);

    $stopA = dwellDay($this->stopLat, $this->stopLng);
    gpsTrack($stopA->route, [
        [$point[0], $point[1], '2026-03-02 10:00:00'],
        [$point[0], $point[1], '2026-03-02 10:10:00'], // +600 s exactos
    ]);
    app(StopDwellService::class)->recomputeForRouteDay($stopA->route);
    expect(StopVisit::where('route_stop_id', $stopA->id)->count())->toBe(1);

    $stopB = dwellDay($this->stopLat, $this->stopLng);
    gpsTrack($stopB->route, [
        [$point[0], $point[1], '2026-03-02 10:00:00'],
        [$point[0], $point[1], '2026-03-02 10:10:01'], // +601 s
    ]);
    app(StopDwellService::class)->recomputeForRouteDay($stopB->route);
    // Se parte en dos fixes sueltos, cada uno por debajo de min_seconds -> ninguna visita queda.
    expect(StopVisit::where('route_stop_id', $stopB->id)->count())->toBe(0);
});

it('un lote de recuperación tras perder cobertura de RED llega igual que si hubiera sido en directo', function () {
    // Simula la cola offline del móvil: todos los fixes de la parada se insertan de golpe, mucho
    // después de capturarse, pero con su `recorded_at` real — StopDwellService no sabe ni le
    // importa cuándo llegaron, solo cuándo se capturaron.
    $stop = dwellDay($this->stopLat, $this->stopLng);
    $point = metersOffset($this->stopLat, $this->stopLng, 20, 0);

    gpsTrack($stop->route, denseRun($point, '10:00:00', '10:07:00'));

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    $visit = StopVisit::where('route_stop_id', $stop->id)->sole();
    expect($visit->entered_at->format('H:i:s'))->toBe('10:00:00')
        ->and($visit->left_at->format('H:i:s'))->toBe('10:06:45') // último múltiplo de 45 s <= 7 min
        ->and($visit->seconds)->toBe(405);
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

/*
| Paradas NO programadas (2026-09-13): tramos ≥ unplanned_stop_min_seconds en un punto que
| no es ni una parada de la ruta ni la base. Mismas fixtures/helpers que arriba.
*/

it('detecta una parada no programada de al menos el umbral configurado', function () {
    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);
    config()->set('servalillo.dwell.unplanned_stop_radius_meters', 100);

    $day = makeRoute('2026-03-02');
    $point = metersOffset($this->stopLat, $this->stopLng, 1000, 0); // lejos de cualquier parada/base
    gpsTrack($day, denseRun($point, '10:00:00', '10:08:00')); // 11 fixes cada 45 s => 450 s

    app(StopDwellService::class)->recomputeForRouteDay($day);

    $unplanned = UnplannedStop::where('route_id', $day->id)->sole();
    expect($unplanned->seconds)->toBe(450)
        ->and($unplanned->left_at)->not->toBeNull()
        ->and((float) $unplanned->latitude)->toEqualWithDelta($point[0], 0.001)
        ->and((float) $unplanned->longitude)->toEqualWithDelta($point[1], 0.001);
});

it('no cuenta un paso corto por debajo del umbral como parada no programada', function () {
    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);

    $day = makeRoute('2026-03-02');
    $point = metersOffset($this->stopLat, $this->stopLng, 1000, 0);
    gpsTrack($day, denseRun($point, '10:00:00', '10:01:30')); // 90 s < 300 s

    app(StopDwellService::class)->recomputeForRouteDay($day);

    expect(UnplannedStop::where('route_id', $day->id)->count())->toBe(0);
});

it('no marca como no programado un tramo en el que el camión circula despacio entre dos paradas (bug real 2026-09-14)', function () {
    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);
    config()->set('servalillo.dwell.unplanned_stop_radius_meters', 100);

    $day = makeRoute('2026-03-02');

    // Circulando muy despacio (tráfico, calles estrechas, giros): 40 m cada 45 s (~3,2 km/h),
    // sin parar en ningún momento durante 7,5 min — cada salto queda muy por debajo del radio
    // de agrupación (100 m), pero el recorrido total (~400 m) lo supera de sobra. Antes del
    // fix, comparar cada fix con el ANTERIOR (no con el ancla del grupo) dejaba que esta cadena
    // de saltos cortos se contara entera como una única parada no programada.
    $fixes = [];
    for ($i = 0; $i < 11; $i++) {
        [$lat, $lng] = metersOffset($this->stopLat, $this->stopLng, $i * 40, 0);
        $fixes[] = [$lat, $lng, Carbon::parse('2026-03-02 10:00:00')->addSeconds(45 * $i)->format('Y-m-d H:i:s')];
    }
    gpsTrack($day, $fixes);

    app(StopDwellService::class)->recomputeForRouteDay($day);

    expect(UnplannedStop::where('route_id', $day->id)->count())->toBe(0);
});

it('un fix con velocidad real de circulación nunca es candidato a parada no programada', function () {
    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 60);
    config()->set('servalillo.dwell.moving_speed_min_mps', 1.0);

    $day = makeRoute('2026-03-02');
    $point = metersOffset($this->stopLat, $this->stopLng, 1000, 0);

    // Mismo punto, mismo intervalo que un caso que SÍ se detectaría (ver arriba) — pero aquí
    // cada fix trae una velocidad real de circulación (5 m/s), así que ninguno debe contar
    // como candidato a parada, por juntos que estén entre sí.
    $fixes = [];
    for ($i = 0; $i < 5; $i++) {
        $fixes[] = [$point[0], $point[1], Carbon::parse('2026-03-02 10:00:00')->addSeconds(45 * $i)->format('Y-m-d H:i:s'), 10, 5.0];
    }
    gpsTrack($day, $fixes);

    app(StopDwellService::class)->recomputeForRouteDay($day);

    expect(UnplannedStop::where('route_id', $day->id)->count())->toBe(0);
});

it('no cuenta como no programada una parada cerca de una parada de la ruta', function () {
    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);

    $stop = dwellDay($this->stopLat, $this->stopLng);
    $point = metersOffset($this->stopLat, $this->stopLng, 40, 0); // dentro del radio de la parada
    gpsTrack($stop->route, denseRun($point, '10:00:00', '10:08:00'));

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    expect(UnplannedStop::where('route_id', $stop->route->id)->count())->toBe(0)
        ->and(StopVisit::where('route_stop_id', $stop->id)->exists())->toBeTrue();
});

it('detecta una parada no programada en el mismo sitio que una parada de la ruta ya CERRADA, después de cerrarse (bug real 2026-09-14)', function () {
    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);

    $stop = dwellDay($this->stopLat, $this->stopLng);
    // La parada se cierra a las 10:10 — updated_at es la única marca de tiempo disponible
    // (completed_at solo existe para "completed", no para failed/skipped).
    RouteStop::where('id', $stop->id)->update([
        'status' => RouteStopStatus::Completed,
        'updated_at' => Carbon::parse('2026-03-02 10:10:00'),
    ]);

    // El camión vuelve a la misma zona bastante después de que la parada quedara cerrada.
    $point = metersOffset($this->stopLat, $this->stopLng, 40, 0); // dentro del radio de la parada
    gpsTrack($stop->route, denseRun($point, '14:00:00', '14:08:00'));

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    $unplanned = UnplannedStop::where('route_id', $stop->route->id)->sole();
    expect($unplanned->seconds)->toBe(450);
});

it('no cuenta como no programada una parada dentro del radio de una parada de la ruta todavía PENDIENTE, sea la hora que sea', function () {
    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);

    $stop = dwellDay($this->stopLat, $this->stopLng); // Pending por defecto, nunca se cierra
    $point = metersOffset($this->stopLat, $this->stopLng, 40, 0);
    gpsTrack($stop->route, denseRun($point, '14:00:00', '14:08:00'));

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    expect(UnplannedStop::where('route_id', $stop->route->id)->count())->toBe(0);
});

it('no cuenta como no programada una parada cerca de la base', function () {
    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);
    config()->set('servalillo.base.latitude', $this->stopLat);
    config()->set('servalillo.base.longitude', $this->stopLng);
    config()->set('servalillo.dwell.exclude_base_radius_meters', 150);

    $day = makeRoute('2026-03-02');
    $point = metersOffset($this->stopLat, $this->stopLng, 40, 0); // dentro del radio de exclusión de la base
    gpsTrack($day, denseRun($point, '10:00:00', '10:08:00'));

    app(StopDwellService::class)->recomputeForRouteDay($day);

    expect(UnplannedStop::where('route_id', $day->id)->count())->toBe(0);
});

it('detecta dos paradas no programadas distintas si el camión se mueve entre ellas', function () {
    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);

    $day = makeRoute('2026-03-02');
    $pointA = metersOffset($this->stopLat, $this->stopLng, 1000, 0);
    $pointB = metersOffset($this->stopLat, $this->stopLng, 1000, 1000); // bien lejos de A

    gpsTrack($day, array_merge(
        denseRun($pointA, '10:00:00', '10:08:00'),
        denseRun($pointB, '11:00:00', '11:08:00'),
    ));

    app(StopDwellService::class)->recomputeForRouteDay($day);

    expect(UnplannedStop::where('route_id', $day->id)->count())->toBe(2);
});

it('deja la parada no programada abierta si el último fix conocido sigue ahí', function () {
    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);
    Carbon::setTestNow(Carbon::parse('2026-03-02 10:10:00')); // poco después del último fix

    $day = makeRoute('2026-03-02');
    $point = metersOffset($this->stopLat, $this->stopLng, 1000, 0);
    gpsTrack($day, denseRun($point, '10:00:00', '10:08:00'));

    app(StopDwellService::class)->recomputeForRouteDay($day);

    $unplanned = UnplannedStop::where('route_id', $day->id)->sole();
    expect($unplanned->left_at)->toBeNull()
        ->and($unplanned->seconds)->toBeNull();
});

it('paradas no programadas: es idempotente', function () {
    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);

    $day = makeRoute('2026-03-02');
    $point = metersOffset($this->stopLat, $this->stopLng, 1000, 0);
    gpsTrack($day, denseRun($point, '10:00:00', '10:08:00'));

    $svc = app(StopDwellService::class);
    $svc->recomputeForRouteDay($day);
    $svc->recomputeForRouteDay($day->fresh());

    expect(UnplannedStop::where('route_id', $day->id)->count())->toBe(1);
});

/*
| Notificar a administración por la campana al detectar una parada no programada
| (petición del usuario, 2026-09-14) — App\Notifications\UnplannedStopDetected, disparada
| desde StopDwellService::run(). `notified_at` (columna nueva en unplanned_stops) se
| empareja entre recálculos por `entered_at` para no repetir el aviso de la misma parada.
*/

it('notifica a administración al detectar una parada no programada', function () {
    Notification::fake();
    $admin = makeUser('administrador');

    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);

    $day = makeRoute('2026-03-02');
    $point = metersOffset($this->stopLat, $this->stopLng, 1000, 0);
    gpsTrack($day, denseRun($point, '10:00:00', '10:08:00')); // 450 s

    app(StopDwellService::class)->recomputeForRouteDay($day);

    Notification::assertSentTo($admin, UnplannedStopDetected::class,
        fn ($n) => $n->seconds === 450 && $n->routeDate === '2026-03-02' && $n->driverName === $day->driver->user->name);
});

it('mantenimiento también recibe el aviso de parada no programada (2026-09-24)', function () {
    Notification::fake();
    $mantenimiento = makeUser('mantenimiento');

    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);

    $day = makeRoute('2026-03-02');
    $point = metersOffset($this->stopLat, $this->stopLng, 1000, 0);
    gpsTrack($day, denseRun($point, '10:00:00', '10:08:00'));

    app(StopDwellService::class)->recomputeForRouteDay($day);

    Notification::assertSentTo($mantenimiento, UnplannedStopDetected::class);
});

it('no vuelve a notificar la misma parada no programada tras recalcular', function () {
    Notification::fake();
    $admin = makeUser('administrador');

    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);

    $day = makeRoute('2026-03-02');
    $point = metersOffset($this->stopLat, $this->stopLng, 1000, 0);
    gpsTrack($day, denseRun($point, '10:00:00', '10:08:00'));

    $svc = app(StopDwellService::class);
    $svc->recomputeForRouteDay($day);
    $svc->recomputeForRouteDay($day->fresh());
    $svc->recomputeForRouteDay($day->fresh());

    Notification::assertSentToTimes($admin, UnplannedStopDetected::class, 1);
});

it('detecta dos paradas no programadas distintas y avisa de las dos por separado', function () {
    Notification::fake();
    $admin = makeUser('administrador');

    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);

    $day = makeRoute('2026-03-02');
    $pointA = metersOffset($this->stopLat, $this->stopLng, 1000, 0);
    $pointB = metersOffset($this->stopLat, $this->stopLng, 1000, 1000);

    gpsTrack($day, array_merge(
        denseRun($pointA, '10:00:00', '10:08:00'),
        denseRun($pointB, '11:00:00', '11:08:00'),
    ));

    app(StopDwellService::class)->recomputeForRouteDay($day);

    Notification::assertSentToTimes($admin, UnplannedStopDetected::class, 2);
});

it('una visita normal a una parada de la ruta no notifica nada', function () {
    Notification::fake();
    $admin = makeUser('administrador');

    $stop = dwellDay($this->stopLat, $this->stopLng);
    $point = metersOffset($this->stopLat, $this->stopLng, 40, 0);
    gpsTrack($stop->route, denseRun($point, '10:00:00', '10:08:00'));

    app(StopDwellService::class)->recomputeForRouteDay($stop->route);

    Notification::assertNothingSentTo($admin);
});

it('un chofer no recibe la notificación de parada no programada', function () {
    Notification::fake();
    $chofer = makeUser('chofer');

    config()->set('servalillo.dwell.unplanned_stop_min_seconds', 300);

    $day = makeRoute('2026-03-02');
    $point = metersOffset($this->stopLat, $this->stopLng, 1000, 0);
    gpsTrack($day, denseRun($point, '10:00:00', '10:08:00'));

    app(StopDwellService::class)->recomputeForRouteDay($day);

    Notification::assertNothingSentTo($chofer);
});
