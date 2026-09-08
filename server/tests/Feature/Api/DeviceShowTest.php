<?php

use App\Models\Device;
use App\Models\Driver;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

function deviceToken(Device $device): string
{
    return $device->createToken('tracker', ['gps:ingest'])->plainTextToken;
}

it('devuelve el estado de un dispositivo sin chofer asignado', function () {
    $device = Device::factory()->create(['label' => 'Tracker 1']);

    $this->withToken(deviceToken($device))
        ->getJson('/api/device')
        ->assertOk()
        ->assertJsonPath('device_id', $device->id)
        ->assertJsonPath('label', 'Tracker 1')
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('driver', null)
        ->assertJsonStructure([
            'device_id', 'label', 'is_active', 'driver',
            'tracking' => ['ping_interval_seconds', 'ping_distance_meters', 'pause_start', 'pause_end'],
            'server_time',
        ]);
});

it('incluye el nombre del chofer cuando está asignado', function () {
    $driver = Driver::factory()->for(User::factory()->state(['name' => 'Pedro Ramírez']), 'user')->create();
    $device = Device::factory()->forDriver($driver)->create();

    $this->withToken(deviceToken($device))
        ->getJson('/api/device')
        ->assertOk()
        ->assertJsonPath('driver.name', 'Pedro Ramírez');
});

it('un dispositivo desactivado recibe 200 con is_active false, no 403', function () {
    $device = Device::factory()->inactive()->create();

    $this->withToken(deviceToken($device))
        ->getJson('/api/device')
        ->assertOk()
        ->assertJsonPath('is_active', false);
});

it('devuelve un server_time parseable en ISO 8601', function () {
    $device = Device::factory()->create();

    $time = $this->withToken(deviceToken($device))
        ->getJson('/api/device')
        ->assertOk()
        ->json('server_time');

    expect(fn () => Carbon::parse($time))->not->toThrow(Exception::class);
});

it('rechaza la petición sin token', function () {
    $this->getJson('/api/device')->assertUnauthorized();
});

it('rechaza un token de usuario aunque tenga la habilidad', function () {
    Sanctum::actingAs(makeUser('chofer'), ['gps:ingest']);

    $this->getJson('/api/device')->assertForbidden();
});

it('rechaza un token de dispositivo sin la habilidad gps:ingest', function () {
    $device = Device::factory()->create();
    $token = $device->createToken('otro', ['algo:else'])->plainTextToken;

    $this->withToken($token)->getJson('/api/device')->assertForbidden();
});
