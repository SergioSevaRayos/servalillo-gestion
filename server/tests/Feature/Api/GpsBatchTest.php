<?php

use App\Models\Device;
use App\Models\Driver;
use App\Models\GpsPosition;
use App\Models\RouteDay;
use App\Models\Truck;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function tokenFor(Device $device): string
{
    return $device->createToken('tracker', ['gps:ingest'])->plainTextToken;
}

function positions(int $n = 1, array $overrides = []): array
{
    return collect(range(1, $n))->map(fn ($i) => array_merge([
        'lat' => 28.40 + $i / 1000,
        'lng' => -16.25 - $i / 1000,
        'recorded_at' => now()->subMinutes($n - $i + 1)->toIso8601String(),
        'battery_level' => 90,
    ], $overrides))->all();
}

it('acepta un lote y resuelve chofer/camión/ruta desde la ruta del chofer', function () {
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    $truck = Truck::factory()->create();
    $route = RouteDay::factory()->create([
        'driver_id' => $driver->id, 'truck_id' => $truck->id, 'route_date' => today(),
    ]);
    $device = Device::factory()->forDriver($driver)->create();

    $this->withToken(tokenFor($device))
        ->postJson('/api/gps/batch', ['positions' => positions(3)])
        ->assertStatus(202)
        ->assertJsonPath('accepted', 3);

    $rows = GpsPosition::all();
    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('driver_id')->unique()->all())->toBe([$driver->id])
        ->and($rows->pluck('truck_id')->unique()->all())->toBe([$truck->id])
        ->and($rows->pluck('route_id')->unique()->all())->toBe([$route->id])
        ->and($device->fresh()->last_seen_at)->not->toBeNull();
});

it('guarda posiciones sin camión ni ruta si el chofer no tiene ruta ese día', function () {
    $driver = Driver::factory()->for(User::factory(), 'user')->create();
    $device = Device::factory()->forDriver($driver)->create();

    $this->withToken(tokenFor($device))
        ->postJson('/api/gps/batch', ['positions' => positions(2)])
        ->assertStatus(202);

    $row = GpsPosition::first();
    expect($row->driver_id)->toBe($driver->id)
        ->and($row->truck_id)->toBeNull()
        ->and($row->route_id)->toBeNull();
});

it('guarda posiciones de un dispositivo sin chofer asignado', function () {
    $device = Device::factory()->create(); // driver_id null

    $this->withToken(tokenFor($device))
        ->postJson('/api/gps/batch', ['positions' => positions(1)])
        ->assertStatus(202);

    $row = GpsPosition::first();
    expect($row->device_id)->toBe($device->id)
        ->and($row->driver_id)->toBeNull()
        ->and($row->truck_id)->toBeNull();
});

it('rechaza un token sin la habilidad gps:ingest', function () {
    $device = Device::factory()->create();
    $token = $device->createToken('otro', ['algo:else'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/gps/batch', ['positions' => positions(1)])
        ->assertForbidden();
});

it('rechaza un token de usuario aunque tenga la habilidad', function () {
    Sanctum::actingAs(makeUser('chofer'), ['gps:ingest']);

    $this->postJson('/api/gps/batch', ['positions' => positions(1)])
        ->assertForbidden();
});

it('rechaza la petición sin autenticación', function () {
    $this->postJson('/api/gps/batch', ['positions' => positions(1)])->assertUnauthorized();
});

it('rechaza un payload sin latitud', function () {
    $device = Device::factory()->create();

    $this->withToken(tokenFor($device))
        ->postJson('/api/gps/batch', ['positions' => [['lng' => -16.2, 'recorded_at' => now()->toIso8601String()]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('positions.0.lat');
});

it('rechaza un lote de más de 500 posiciones', function () {
    $device = Device::factory()->create();

    $this->withToken(tokenFor($device))
        ->postJson('/api/gps/batch', ['positions' => positions(501)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('positions');
});

it('descarta las posiciones con fecha muy futura', function () {
    $device = Device::factory()->create();

    $this->withToken(tokenFor($device))
        ->postJson('/api/gps/batch', ['positions' => [
            ['lat' => 28.4, 'lng' => -16.2, 'recorded_at' => now()->toIso8601String()],
            ['lat' => 28.5, 'lng' => -16.3, 'recorded_at' => now()->addHours(3)->toIso8601String()],
        ]])
        ->assertStatus(202)
        ->assertJsonPath('accepted', 1);

    expect(GpsPosition::count())->toBe(1);
});

it('un dispositivo desactivado recibe 403', function () {
    $device = Device::factory()->inactive()->create();

    $this->withToken(tokenFor($device))
        ->postJson('/api/gps/batch', ['positions' => positions(1)])
        ->assertForbidden();
});
