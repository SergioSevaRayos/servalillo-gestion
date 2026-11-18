<?php

use App\Livewire\Fleet\Map;
use App\Models\Device;
use App\Models\Driver;
use App\Models\GpsPosition;
use App\Models\Truck;
use Livewire\Livewire;

function fleetDevice(string $name, ?int $minutesAgo, array $deviceState = []): Device
{
    $user = makeUser('chofer');
    $user->update(['name' => $name]);
    $driver = Driver::factory()->create(['user_id' => $user->id]);
    $device = Device::factory()->create(['driver_id' => $driver->id, ...$deviceState]);

    if ($minutesAgo !== null) {
        GpsPosition::factory()->create([
            'device_id' => $device->id,
            'truck_id' => Truck::factory()->create()->id,
            'driver_id' => $driver->id,
            'latitude' => 36.8769,
            'longitude' => -2.4431,
            'speed_mps' => 10,
            'recorded_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    return $device;
}

it('administrador y mantenimiento ven el mapa; el chofer no', function () {
    $this->actingAs(makeUser('administrador'))->get('/mapa')->assertOk();
    $this->actingAs(makeUser('mantenimiento'))->get('/mapa')->assertOk();
    $this->actingAs(makeUser('chofer'))->get('/mapa')->assertForbidden();
});

it('clasifica cada camión: en línea, sin señal o sin datos', function () {
    fleetDevice('Pedro Online', 1);
    fleetDevice('Luis Parado', 30);
    fleetDevice('Ana Nueva', null);

    $fleet = collect(Livewire::actingAs(makeUser('administrador'))->test(Map::class)->instance()->fleet)->keyBy('driver');

    expect($fleet['Pedro Online']['state'])->toBe('fresh')
        ->and($fleet['Pedro Online']['speed_kmh'])->toBe(36)
        ->and($fleet['Luis Parado']['state'])->toBe('stale')
        ->and($fleet['Luis Parado']['minutes_ago'])->toBe(30)
        ->and($fleet['Ana Nueva']['state'])->toBe('none')
        ->and($fleet['Ana Nueva']['lat'])->toBeNull();
});

it('toma la última posición por fecha de registro, no por orden de llegada', function () {
    $device = fleetDevice('Pedro Lote', 2);
    // Lote de recuperación que llega DESPUÉS pero con una hora más antigua.
    GpsPosition::factory()->create([
        'device_id' => $device->id, 'truck_id' => Truck::factory()->create()->id,
        'latitude' => 10.0, 'longitude' => 10.0, 'recorded_at' => now()->subHours(3),
    ]);

    $fleet = Livewire::actingAs(makeUser('administrador'))->test(Map::class)->instance()->fleet;

    expect($fleet[0]['lat'])->toBe(36.8769)->and($fleet[0]['state'])->toBe('fresh');
});

it('no muestra dispositivos inactivos ni sin chofer', function () {
    fleetDevice('Activo Pedro', 1);
    fleetDevice('Inactivo Luis', 1, ['is_active' => false]);
    Device::factory()->create(['driver_id' => null]);

    $names = collect(Livewire::actingAs(makeUser('administrador'))->test(Map::class)->instance()->fleet)->pluck('driver');

    expect($names->all())->toBe(['Activo Pedro']);
});

it('pinta la lista y el poll avisa al mapa con los datos nuevos', function () {
    fleetDevice('Pedro Online', 1);

    $test = Livewire::actingAs(makeUser('administrador'))->test(Map::class);

    expect($test->html())->toContain('Pedro Online')->toContain('En línea')->toContain('fleetMap');

    $test->call('refreshMap')->assertDispatched('fleet-map-updated');
});
