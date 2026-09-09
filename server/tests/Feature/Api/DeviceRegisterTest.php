<?php

use App\Models\Device;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    config()->set('servalillo.device.enrolment_secret', 'test-enrolment-secret');
    RateLimiter::clear('device-register');
});

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'install_identifier' => 'phone-abc-123',
        'secret' => 'test-enrolment-secret',
        'platform' => 'android',
        'app_version' => '2.1.0',
    ], $overrides);
}

it('enrola un dispositivo con el secreto correcto y devuelve un token con habilidad gps:ingest', function () {
    $response = $this->postJson('/api/device/register', registerPayload());

    $response->assertCreated()
        ->assertJsonStructure(['token', 'device_id', 'tracking' => ['ping_interval_seconds', 'pause_start']]);

    $device = Device::firstWhere('install_identifier', 'phone-abc-123');
    expect($device)->not->toBeNull()
        ->and($device->driver_id)->toBeNull()
        ->and($device->is_active)->toBeTrue()
        ->and($device->app_version)->toBe('2.1.0')
        ->and($device->tokens()->first()->abilities)->toBe(['gps:ingest']);
});

it('rechaza el enrolamiento con el secreto incorrecto', function () {
    $this->postJson('/api/device/register', registerPayload(['secret' => 'nope']))
        ->assertForbidden();

    expect(Device::count())->toBe(0);
});

it('rechaza el enrolamiento si el secreto de config está vacío', function () {
    config()->set('servalillo.device.enrolment_secret', '');

    $this->postJson('/api/device/register', registerPayload(['secret' => 'cualquiera']))
        ->assertForbidden();
});

it('re-enrolar el mismo identificador revoca el token anterior', function () {
    $first = $this->postJson('/api/device/register', registerPayload())->json('token');
    $second = $this->postJson('/api/device/register', registerPayload())->json('token');

    $device = Device::firstWhere('install_identifier', 'phone-abc-123');

    expect($device->tokens()->count())->toBe(1)
        ->and($second)->not->toBe($first);

    // El token viejo ya no vale.
    $this->withToken($first)->postJson('/api/gps/batch', ['positions' => []])->assertUnauthorized();
});

it('limita el enrolamiento por IP', function () {
    foreach (range(1, 30) as $i) {
        $this->postJson('/api/device/register', registerPayload(['install_identifier' => "phone-$i"]));
    }

    $this->postJson('/api/device/register', registerPayload(['install_identifier' => 'phone-31']))
        ->assertStatus(429);
});
