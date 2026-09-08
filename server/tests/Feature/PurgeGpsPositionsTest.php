<?php

use App\Models\Device;
use App\Models\GpsPosition;

it('borra las posiciones GPS más antiguas que la retención y deja las recientes', function () {
    config()->set('servalillo.gps_retention_days', 90);
    $device = Device::factory()->create();

    $mk = fn (string $when) => GpsPosition::insert([
        'device_id' => $device->id, 'latitude' => 28.4, 'longitude' => -16.2,
        'recorded_at' => now()->parse($when), 'created_at' => now(),
    ]);

    $mk('-200 days');
    $mk('-91 days');
    $mk('-10 days');
    $mk('now');

    $this->artisan('gps:purgar')->assertSuccessful();

    expect(GpsPosition::count())->toBe(2)
        ->and(GpsPosition::where('recorded_at', '<', now()->subDays(90))->count())->toBe(0);
});

it('acepta un umbral de días por opción', function () {
    $device = Device::factory()->create();

    GpsPosition::insert([
        'device_id' => $device->id, 'latitude' => 28.4, 'longitude' => -16.2,
        'recorded_at' => now()->subDays(5), 'created_at' => now(),
    ]);

    $this->artisan('gps:purgar', ['--dias' => 3])->assertSuccessful();

    expect(GpsPosition::count())->toBe(0);
});
