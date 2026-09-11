<?php

use App\Models\RouteStop;
use App\Services\StopDwellService;
use Illuminate\Support\Carbon;

/*
| Tiempo de trayecto y velocidad entre dos paradas consecutivas (StopDwellService::transitLegs()).
| No se persiste: se calcula al vuelo a partir de `stop_visits` (ya construidas por el pase de
| permanencia) + una consulta puntual de `gps_positions.speed_mps` en la ventana entre ambas.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-03-02 20:00:00'));
    config()->set('servalillo.dwell.radius_meters', 100);
    config()->set('servalillo.dwell.min_seconds', 120);
    config()->set('servalillo.dwell.merge_gap_seconds', 180);
    config()->set('servalillo.dwell.clamp_to_shift', false);
});

afterEach(fn () => Carbon::setTestNow());

/** Un día de ruta con dos paradas, A y B, separadas ~5 km (fuera de la geocerca la una de la otra). */
function transitDay(): array
{
    $day = makeRoute('2026-03-02');
    $a = RouteStop::factory()->for($day, 'route')->create([
        'position' => 1, 'customer_name' => 'Cliente A', 'latitude' => 28.4500, 'longitude' => -16.3000,
    ]);
    [$bLat, $bLng] = metersOffset(28.4500, -16.3000, 0, 5000);
    $b = RouteStop::factory()->for($day, 'route')->create([
        'position' => 2, 'customer_name' => 'Cliente B', 'latitude' => $bLat, 'longitude' => $bLng,
    ]);

    return [$day, $a, $b];
}

/** Fixes densos (45 s) en un punto fijo entre $from y $from+180s, sin velocidad (parada). */
function stopFixes(float $lat, float $lng, string $from): array
{
    return collect([0, 45, 90, 135, 180])
        ->map(fn ($s) => [$lat, $lng, Carbon::parse($from)->addSeconds($s)->format('Y-m-d H:i:s'), 10, null])
        ->all();
}

it('calcula el tramo entre dos paradas con tiempo y velocidad media/máxima', function () {
    [$day, $a, $b] = transitDay();

    $transitFixes = [
        [28.5000, -16.2000, '2026-03-02 10:04:00', 10, 20.0], // 72 km/h
        [28.5000, -16.2000, '2026-03-02 10:06:00', 10, 25.0], // 90 km/h
        [28.5000, -16.2000, '2026-03-02 10:08:00', 10, 15.0], // 54 km/h
    ];

    gpsTrack($day, array_merge(
        stopFixes((float) $a->latitude, (float) $a->longitude, '2026-03-02 10:00:00'),
        $transitFixes,
        stopFixes((float) $b->latitude, (float) $b->longitude, '2026-03-02 10:10:00'),
    ));

    app(StopDwellService::class)->recomputeForRouteDay($day);

    $legs = app(StopDwellService::class)->transitLegs($day);

    expect($legs)->toHaveCount(1);
    expect($legs[0]['from_stop_id'])->toBe($a->id)
        ->and($legs[0]['to_stop_id'])->toBe($b->id)
        ->and($legs[0]['from_name'])->toBe('Cliente A')
        ->and($legs[0]['to_name'])->toBe('Cliente B')
        ->and($legs[0]['seconds'])->toBeGreaterThan(0)
        ->and($legs[0]['avg_speed_kmh'])->toBe(72) // (20+25+15)/3 m/s = 20 m/s -> 72 km/h
        ->and($legs[0]['max_speed_kmh'])->toBe(90); // 25 m/s -> 90 km/h
});

it('salta una parada intermedia sin datos de geocerca y encadena con la siguiente', function () {
    $day = makeRoute('2026-03-02');
    $a = RouteStop::factory()->for($day, 'route')->create(['position' => 1, 'customer_name' => 'Cliente A', 'latitude' => 28.4500, 'longitude' => -16.3000]);
    RouteStop::factory()->for($day, 'route')->create(['position' => 2, 'customer_name' => 'Cliente C', 'latitude' => null, 'longitude' => null]);
    [$bLat, $bLng] = metersOffset(28.4500, -16.3000, 0, 5000);
    $b = RouteStop::factory()->for($day, 'route')->create(['position' => 3, 'customer_name' => 'Cliente B', 'latitude' => $bLat, 'longitude' => $bLng]);

    gpsTrack($day, array_merge(
        stopFixes((float) $a->latitude, (float) $a->longitude, '2026-03-02 10:00:00'),
        stopFixes((float) $b->latitude, (float) $b->longitude, '2026-03-02 10:10:00'),
    ));

    app(StopDwellService::class)->recomputeForRouteDay($day);

    $legs = app(StopDwellService::class)->transitLegs($day);

    expect($legs)->toHaveCount(1)
        ->and($legs[0]['from_stop_id'])->toBe($a->id)
        ->and($legs[0]['to_stop_id'])->toBe($b->id);
});

it('devuelve una lista vacía si ninguna parada tiene datos de geocerca', function () {
    $day = makeRoute('2026-03-02');
    RouteStop::factory()->for($day, 'route')->create(['position' => 1, 'latitude' => 28.4500, 'longitude' => -16.3000]);

    expect(app(StopDwellService::class)->transitLegs($day))->toBe([]);
});

it('calcula la duración del tramo aunque no haya muestras de velocidad en la ventana', function () {
    [$day, $a, $b] = transitDay();

    gpsTrack($day, array_merge(
        stopFixes((float) $a->latitude, (float) $a->longitude, '2026-03-02 10:00:00'),
        stopFixes((float) $b->latitude, (float) $b->longitude, '2026-03-02 10:10:00'),
    ));

    app(StopDwellService::class)->recomputeForRouteDay($day);

    $legs = app(StopDwellService::class)->transitLegs($day);

    expect($legs)->toHaveCount(1)
        ->and($legs[0]['seconds'])->toBeGreaterThan(0)
        ->and($legs[0]['avg_speed_kmh'])->toBeNull()
        ->and($legs[0]['max_speed_kmh'])->toBeNull();
});
