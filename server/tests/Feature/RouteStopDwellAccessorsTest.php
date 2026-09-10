<?php

use App\Models\RouteStop;
use App\Models\StopVisit;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00')));
afterEach(fn () => Carbon::setTestNow());

it('onSiteSeconds suma varias visitas cerradas', function () {
    $stop = RouteStop::factory()->for(makeRoute('2026-03-10'), 'route')->create();

    StopVisit::factory()->for($stop, 'stop')->create([
        'entered_at' => '2026-03-10 09:00:00', 'left_at' => '2026-03-10 09:10:00', 'seconds' => 600,
    ]);
    StopVisit::factory()->for($stop, 'stop')->create([
        'entered_at' => '2026-03-10 11:00:00', 'left_at' => '2026-03-10 11:05:00', 'seconds' => 300,
    ]);

    $stop->load('visits');

    expect($stop->onSiteSeconds())->toBe(900)
        ->and($stop->isOnSiteNow())->toBeFalse()
        ->and($stop->firstArrivalAt()->format('H:i'))->toBe('09:00')
        ->and($stop->lastDepartureAt()->format('H:i'))->toBe('11:05');
});

it('onSiteSeconds es null cuando no hay visitas', function () {
    $stop = RouteStop::factory()->for(makeRoute('2026-03-10'), 'route')->create();

    expect($stop->onSiteSeconds())->toBeNull()
        ->and($stop->isOnSiteNow())->toBeFalse()
        ->and($stop->firstArrivalAt())->toBeNull()
        ->and($stop->lastDepartureAt())->toBeNull();
});

it('una visita abierta cuenta el tiempo transcurrido y marca "en parada ahora"', function () {
    $stop = RouteStop::factory()->for(makeRoute('2026-03-10'), 'route')->create();

    StopVisit::factory()->for($stop, 'stop')->open()->create(['entered_at' => '2026-03-10 11:50:00']);
    $stop->load('visits');

    expect($stop->isOnSiteNow())->toBeTrue()
        ->and($stop->onSiteSeconds())->toBe(600) // 11:50 -> 12:00
        ->and($stop->lastDepartureAt())->toBeNull();
});
