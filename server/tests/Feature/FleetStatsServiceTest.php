<?php

use App\Enums\OdometerKind;
use App\Enums\RouteStatus;
use App\Enums\RouteStopStatus;
use App\Models\DeliveryType;
use App\Models\Driver;
use App\Models\OdometerReading;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Truck;
use App\Models\User;
use App\Services\FleetStatsService;
use Illuminate\Support\Carbon;

function statsFor(int $daysBack = 30): array
{
    return app(FleetStatsService::class)->report(
        Carbon::today()->subDays($daysBack - 1)->startOfDay(),
        Carbon::today()->endOfDay(),
    );
}

it('calcula tasa de éxito y litros a partir de paradas cerradas', function () {
    $route = Route::factory()->create(['route_date' => today()->subDays(3), 'status' => RouteStatus::Completed]);

    RouteStop::factory()->for($route)->create(['status' => RouteStopStatus::Completed, 'planned_quantity' => 1000, 'delivered_quantity' => 900]);
    RouteStop::factory()->for($route)->create(['status' => RouteStopStatus::Completed, 'planned_quantity' => 500, 'delivered_quantity' => 500]);
    RouteStop::factory()->for($route)->create(['status' => RouteStopStatus::Failed, 'planned_quantity' => 300, 'delivered_quantity' => null]);
    RouteStop::factory()->for($route)->create(['status' => RouteStopStatus::Pending, 'planned_quantity' => 999]);

    $k = statsFor()['kpis'];

    expect($k['stops_completed'])->toBe(2)
        ->and($k['stops_failed'])->toBe(1)
        ->and($k['success_rate'])->toBe(66.7)          // 2 / 3
        ->and($k['liters_delivered'])->toBe(1400.0)    // 900 + 500, la pendiente no cuenta
        ->and($k['fill_rate'])->toBe(77.8);            // 1400 entregado / 1800 planificado (cerradas)
});

it('deja success_rate y fill_rate en null cuando no hay paradas cerradas', function () {
    $route = Route::factory()->create(['route_date' => today()->subDay()]);
    RouteStop::factory()->for($route)->create(['status' => RouteStopStatus::Pending]);

    $k = statsFor()['kpis'];

    expect($k['success_rate'])->toBeNull()
        ->and($k['fill_rate'])->toBeNull();
});

it('ignora lo que cae fuera del rango de fechas', function () {
    $viejo = Route::factory()->create(['route_date' => today()->subDays(40), 'status' => RouteStatus::Completed]);
    RouteStop::factory()->for($viejo)->create(['status' => RouteStopStatus::Completed, 'planned_quantity' => 100, 'delivered_quantity' => 100]);

    expect(statsFor(30)['kpis']['stops_completed'])->toBe(0)
        ->and(statsFor(60)['kpis']['stops_completed'])->toBe(1);
});

it('agrega por chofer', function () {
    $d1 = Driver::factory()->for(User::factory()->state(['name' => 'Ada']), 'user')->create();
    $d2 = Driver::factory()->for(User::factory()->state(['name' => 'Grace']), 'user')->create();

    $r1 = Route::factory()->create(['driver_id' => $d1->id, 'route_date' => today()->subDay()]);
    $r2 = Route::factory()->create(['driver_id' => $d2->id, 'route_date' => today()->subDay()]);

    RouteStop::factory()->for($r1)->count(3)->create(['status' => RouteStopStatus::Completed, 'delivered_quantity' => 100]);
    RouteStop::factory()->for($r1)->create(['status' => RouteStopStatus::Failed]);
    RouteStop::factory()->for($r2)->count(2)->create(['status' => RouteStopStatus::Completed, 'delivered_quantity' => 50]);

    $byDriver = collect(statsFor()['by_driver'])->keyBy('driver');

    expect($byDriver['Ada']['completed'])->toBe(3)
        ->and($byDriver['Ada']['failed'])->toBe(1)
        ->and($byDriver['Ada']['failure_rate'])->toBe(25.0)
        ->and($byDriver['Ada']['liters'])->toBe(300.0)
        ->and($byDriver['Grace']['completed'])->toBe(2)
        ->and($byDriver['Grace']['failure_rate'])->toBe(0.0);
});

it('calcula km por camión solo con lecturas de inicio y fin', function () {
    $truck = Truck::factory()->create(['code' => 'C-99']);

    $conAmbas = Route::factory()->create(['truck_id' => $truck->id, 'route_date' => today()->subDays(2), 'status' => RouteStatus::Completed]);
    OdometerReading::create(['route_id' => $conAmbas->id, 'truck_id' => $truck->id, 'driver_id' => $conAmbas->driver_id, 'kind' => OdometerKind::Start->value, 'value' => 1000, 'recorded_at' => now()]);
    OdometerReading::create(['route_id' => $conAmbas->id, 'truck_id' => $truck->id, 'driver_id' => $conAmbas->driver_id, 'kind' => OdometerKind::End->value, 'value' => 1150, 'recorded_at' => now()]);

    $soloInicio = Route::factory()->create(['truck_id' => $truck->id, 'route_date' => today()->subDay()]);
    OdometerReading::create(['route_id' => $soloInicio->id, 'truck_id' => $truck->id, 'driver_id' => $soloInicio->driver_id, 'kind' => OdometerKind::Start->value, 'value' => 1150, 'recorded_at' => now()]);

    RouteStop::factory()->for($conAmbas)->create(['status' => RouteStopStatus::Completed, 'delivered_quantity' => 700]);

    $row = collect(statsFor()['by_truck'])->firstWhere('truck', 'C-99');

    expect($row['km'])->toBe(150)          // solo la ruta con las dos lecturas
        ->and($row['route_days'])->toBe(2)
        ->and($row['liters'])->toBe(700.0);
});

it('desglosa el volumen por tipo de reparto', function () {
    $gasoleo = DeliveryType::factory()->create(['name' => 'Gasóleo']);
    $agua = DeliveryType::factory()->create(['name' => 'Agua']);
    $route = Route::factory()->create(['route_date' => today()->subDay()]);

    RouteStop::factory()->for($route)->create(['delivery_type_id' => $gasoleo->id, 'status' => RouteStopStatus::Completed, 'planned_quantity' => 1000, 'delivered_quantity' => 1000]);
    RouteStop::factory()->for($route)->create(['delivery_type_id' => $agua->id, 'status' => RouteStopStatus::Completed, 'planned_quantity' => 400, 'delivered_quantity' => 350]);

    $byType = collect(statsFor()['volume']['by_type'])->keyBy('type');

    expect($byType['Gasóleo']['delivered'])->toBe(1000.0)
        ->and($byType['Agua']['delivered'])->toBe(350.0);
});

it('excluye rutas y paradas borradas (soft delete)', function () {
    $route = Route::factory()->create(['route_date' => today()->subDay(), 'status' => RouteStatus::Completed]);
    RouteStop::factory()->for($route)->create(['status' => RouteStopStatus::Completed, 'delivered_quantity' => 100]);
    $borrada = RouteStop::factory()->for($route)->create(['status' => RouteStopStatus::Completed, 'delivered_quantity' => 999]);
    $borrada->delete();

    expect(statsFor()['kpis']['liters_delivered'])->toBe(100.0);
});
