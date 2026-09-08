<?php

use App\Livewire\Maintenance\Devices;
use App\Models\Device;
use App\Models\Driver;
use App\Models\GpsPosition;
use App\Models\User;
use Livewire\Livewire;

function choferDriver(string $name = 'Chofer Test'): Driver
{
    return Driver::factory()->for(User::factory()->state(['name' => $name]), 'user')->create();
}

it('mantenimiento ve la lista de dispositivos', function () {
    Device::factory()->create(['install_identifier' => 'seed-abc']);

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Devices::class)
        ->assertOk()
        ->assertSee('seed-abc');
});

it('un administrador también puede gestionar dispositivos', function () {
    Device::factory()->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Devices::class)
        ->assertOk();
});

it('un chofer no puede acceder al panel de dispositivos', function () {
    Livewire::actingAs(makeUser('chofer'))
        ->test(Devices::class)
        ->assertForbidden();
});

it('asigna un dispositivo a un chofer', function () {
    $device = Device::factory()->create();
    $driver = choferDriver();

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Devices::class)
        ->call('assign', $device->id, (string) $driver->id)
        ->assertDispatched('toast');

    expect($device->fresh()->driver_id)->toBe($driver->id);
});

it('avisa si el chofer ya tiene otro dispositivo asignado', function () {
    $driver = choferDriver();
    Device::factory()->forDriver($driver)->create();
    $other = Device::factory()->create();

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Devices::class)
        ->call('assign', $other->id, (string) $driver->id)
        ->assertDispatched('toast', variant: 'warning');

    expect($other->fresh()->driver_id)->toBeNull();
});

it('desasigna el dispositivo con un valor vacío', function () {
    $driver = choferDriver();
    $device = Device::factory()->forDriver($driver)->create();

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Devices::class)
        ->call('assign', $device->id, '');

    expect($device->fresh()->driver_id)->toBeNull();
});

it('revoca el acceso de un dispositivo', function () {
    $device = Device::factory()->create();
    $device->createToken('t', ['gps:ingest']);

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Devices::class)
        ->call('revoke', $device->id)
        ->assertDispatched('toast');

    expect($device->tokens()->count())->toBe(0);
});

it('activa y desactiva un dispositivo', function () {
    $device = Device::factory()->create(['is_active' => true]);

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Devices::class)
        ->call('toggleActive', $device->id);

    expect($device->fresh()->is_active)->toBeFalse();
});

it('elimina un dispositivo y sus posiciones', function () {
    $device = Device::factory()->create();
    GpsPosition::insert([
        'device_id' => $device->id, 'latitude' => 28.4, 'longitude' => -16.2,
        'recorded_at' => now(), 'created_at' => now(),
    ]);

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Devices::class)
        ->call('delete', $device->id);

    expect(Device::find($device->id))->toBeNull()
        ->and(GpsPosition::count())->toBe(0);
});
