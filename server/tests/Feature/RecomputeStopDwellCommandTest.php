<?php

use App\Enums\RouteStatus;
use App\Models\RouteStop;
use App\Models\StopVisit;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-03-10 20:00:00'));
    config()->set('servalillo.dwell.clamp_to_shift', false);
});

afterEach(fn () => Carbon::setTestNow());

/** Un día de ruta con una parada visitada durante ~7 min. */
function dwellVisitedDay(string $date): RouteStop
{
    $day = makeRoute($date);
    $stop = RouteStop::factory()->for($day, 'route')->create([
        'position' => 1, 'latitude' => 28.45, 'longitude' => -16.30,
    ]);

    [$lat, $lng] = metersOffset(28.45, -16.30, 20, 0);
    $fixes = [];
    for ($i = 0; $i < 10; $i++) {
        $fixes[] = [$lat, $lng, Carbon::parse("{$date} 10:00:00")->addSeconds(45 * $i)->format('Y-m-d H:i:s')];
    }
    gpsTrack($day, $fixes);

    return $stop;
}

it('sin argumento recalcula ayer y hoy', function () {
    $yesterday = dwellVisitedDay('2026-03-09');
    $today = dwellVisitedDay('2026-03-10');
    $old = dwellVisitedDay('2026-03-06'); // hace 4 días: no se toca

    $this->artisan('paradas:calcular-permanencia')->assertSuccessful();

    expect(StopVisit::where('route_stop_id', $yesterday->id)->count())->toBe(1)
        ->and(StopVisit::where('route_stop_id', $today->id)->count())->toBe(1)
        ->and(StopVisit::where('route_stop_id', $old->id)->count())->toBe(0);
});

it('acepta una fecha concreta', function () {
    $old = dwellVisitedDay('2026-03-06');

    $this->artisan('paradas:calcular-permanencia', ['fecha' => '2026-03-06'])->assertSuccessful();

    expect(StopVisit::where('route_stop_id', $old->id)->count())->toBe(1);
});

it('sella dwell_recalculated_at', function () {
    $today = dwellVisitedDay('2026-03-10');

    $this->artisan('paradas:calcular-permanencia')->assertSuccessful();

    expect($today->route->fresh()->dwell_recalculated_at)->not->toBeNull();
});

it('también cierra los días ya completados (es lo que hace el pase nocturno)', function () {
    $stop = dwellVisitedDay('2026-03-09');
    $stop->route->update(['status' => RouteStatus::Completed]);

    $this->artisan('paradas:calcular-permanencia')->assertSuccessful();

    expect(StopVisit::where('route_stop_id', $stop->id)->count())->toBe(1);
});
