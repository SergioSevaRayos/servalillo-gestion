<?php

use App\Livewire\Chofer\Today;
use App\Livewire\Routes\Board;
use App\Livewire\Routes\History;
use App\Models\Driver;
use App\Models\Route;
use App\Models\RouteDay;
use App\Models\RouteStop;
use App\Models\StopVisit;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-03-10 20:00:00'));
    config()->set('servalillo.dwell.clamp_to_shift', false);
});

afterEach(fn () => Carbon::setTestNow());

/** Ruta de hoy con una parada visitada ~7 min; devuelve la parada. */
function viewedDwellStop(): RouteStop
{
    $day = makeRoute('2026-03-10');
    $stop = RouteStop::factory()->for($day, 'route')->create([
        'position' => 1, 'customer_name' => 'Bar Central', 'latitude' => 28.45, 'longitude' => -16.30,
    ]);

    [$lat, $lng] = metersOffset(28.45, -16.30, 20, 0);
    $fixes = [];
    for ($i = 0; $i < 10; $i++) {
        $fixes[] = [$lat, $lng, Carbon::parse('2026-03-10 10:00:00')->addSeconds(45 * $i)->format('Y-m-d H:i:s')];
    }
    gpsTrack($day, $fixes);

    return $stop;
}

it('el tablero recalcula la permanencia de los días visibles caducados', function () {
    $stop = viewedDwellStop();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-03-10')
        ->assertSee('min');

    expect(StopVisit::where('route_stop_id', $stop->id)->count())->toBe(1)
        ->and($stop->route->fresh()->dwell_recalculated_at)->not->toBeNull();
});

it('el tablero no recalcula si el sello es reciente', function () {
    $stop = viewedDwellStop();
    $stop->route->forceFill(['dwell_recalculated_at' => now()])->saveQuietly();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-03-10');

    expect(StopVisit::count())->toBe(0); // no se recalculó
});

it('el tablero no recalcula días demasiado viejos', function () {
    $day = makeRoute('2026-03-01'); // hace 9 días
    RouteStop::factory()->for($day, 'route')->create(['position' => 1, 'latitude' => 28.45, 'longitude' => -16.30]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-03-01');

    expect(StopVisit::count())->toBe(0);
});

it('la web del chofer muestra la línea de permanencia de su ruta', function () {
    $chofer = makeUser('chofer');
    $driver = Driver::factory()->create(['user_id' => $chofer->id]);
    $route = Route::factory()->create(['driver_id' => $driver->id, 'valid_from' => '2020-01-01', 'valid_until' => null]);
    $day = RouteDay::factory()->create([
        'route_id' => $route->id, 'truck_id' => $route->truck_id, 'driver_id' => $driver->id,
        'route_date' => '2026-03-10',
    ]);
    $stop = RouteStop::factory()->for($day, 'route')->create([
        'position' => 1, 'latitude' => 28.45, 'longitude' => -16.30,
    ]);

    [$lat, $lng] = metersOffset(28.45, -16.30, 20, 0);
    $fixes = [];
    for ($i = 0; $i < 10; $i++) {
        $fixes[] = [$lat, $lng, Carbon::parse('2026-03-10 10:00:00')->addSeconds(45 * $i)->format('Y-m-d H:i:s')];
    }
    gpsTrack($day, $fixes);

    Livewire::actingAs($chofer)
        ->test(Today::class)->set('date', '2026-03-10')
        ->assertSee('en parada');

    expect(StopVisit::where('route_stop_id', $stop->id)->count())->toBe(1);
});

it('el historial recalcula un día reciente al "Ver detalle" pero no uno viejo', function () {
    // día viejo: no se recalcula bajo demanda
    $old = makeRoute('2026-02-20');
    $oldStop = RouteStop::factory()->for($old, 'route')->create(['position' => 1, 'latitude' => 28.45, 'longitude' => -16.30]);
    [$lat, $lng] = metersOffset(28.45, -16.30, 20, 0);
    $fixes = [];
    for ($i = 0; $i < 10; $i++) {
        $fixes[] = [$lat, $lng, Carbon::parse('2026-02-20 10:00:00')->addSeconds(45 * $i)->format('Y-m-d H:i:s')];
    }
    gpsTrack($old, $fixes);

    Livewire::actingAs(makeUser('administrador'))
        ->test(History::class, ['route' => $old->route])
        ->call('viewDay', $old->id);

    expect(StopVisit::where('route_stop_id', $oldStop->id)->count())->toBe(0);
});

it('renders repetidos del tablero mantienen estable el número de visitas', function () {
    $stop = viewedDwellStop();

    $component = Livewire::actingAs(makeUser('administrador'))
        ->test(Board::class)->set('date', '2026-03-10');

    $component->call('$refresh')->call('$refresh');

    expect(StopVisit::where('route_stop_id', $stop->id)->count())->toBe(1);
});
