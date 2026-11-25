<?php

use App\Livewire\Devices\Index as Devices;
use App\Models\Device;
use App\Models\Driver;
use App\Models\GpsPosition;
use App\Models\User;
use App\Services\DevicePinHasher;
use App\Services\TrackerApk;
use Illuminate\Support\Facades\Storage;
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

it('localizar abre el mapa con la última posición y el rastro', function () {
    $driver = choferDriver('Lucía Gómez');
    $device = Device::factory()->forDriver($driver)->create(['label' => 'Móvil C-02']);

    GpsPosition::insert([
        ['device_id' => $device->id, 'latitude' => 38.10, 'longitude' => -0.80, 'accuracy_m' => null, 'battery_level' => null, 'recorded_at' => now()->subMinutes(5), 'created_at' => now()],
        ['device_id' => $device->id, 'latitude' => 38.12, 'longitude' => -0.82, 'accuracy_m' => 15.5, 'battery_level' => 60, 'recorded_at' => now()->subMinute(), 'created_at' => now()],
    ]);

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Devices::class)
        ->call('locate', $device->id)
        ->assertDispatched('open-device-map', function (string $event, array $params) {
            return $params['label'] === 'Móvil C-02'
                && $params['driver'] === 'Lucía Gómez'
                && $params['last']['lat'] === 38.12
                && $params['last']['battery_level'] === 60
                && count($params['trail']) === 2
                && $params['trail'][0] === [38.10, -0.80]; // rastro en orden cronológico
        });
});

it('localizar avisa si el dispositivo no ha enviado posiciones', function () {
    $device = Device::factory()->create();

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Devices::class)
        ->call('locate', $device->id)
        ->assertDispatched('toast', variant: 'warning')
        ->assertNotDispatched('open-device-map');
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

// --- Pantalla en Gestión (docs/24) ---

it('administrador y mantenimiento abren /dispositivos; el chofer no', function () {
    $this->actingAs(makeUser('administrador'))->get(route('devices.index'))->assertOk();
    $this->actingAs(makeUser('mantenimiento'))->get(route('devices.index'))->assertOk();
    $this->actingAs(makeUser('chofer'))->get(route('devices.index'))->assertForbidden();
});

it('la ruta antigua bajo /mantenimiento ya no existe', function () {
    $this->actingAs(makeUser('mantenimiento'))->get('/mantenimiento/dispositivos')->assertNotFound();
});

// --- Protección del terminal (docs/24) ---

it('guarda las restricciones y la clave como hash verificable', function () {
    $device = Device::factory()->create();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Devices::class)
        ->call('editSecurity', $device->id)
        ->assertDispatched('open-modal', 'device-security')
        ->set('security.block_screen_capture', true)
        ->set('security.block_uninstall', true)
        ->set('security.pin', '4821')
        ->set('security.pin_confirmation', '4821')
        ->call('saveSecurity')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal', 'device-security');

    $device->refresh();

    expect($device->block_screen_capture)->toBeTrue()
        ->and($device->block_uninstall)->toBeTrue()
        ->and($device->block_factory_reset)->toBeFalse()
        ->and($device->unlock_pin_hash)->not->toContain('4821')
        ->and(app(DevicePinHasher::class)->check('4821', $device->unlock_pin_hash, $device->unlock_pin_salt))->toBeTrue();
});

it('exige clave la primera vez y valida su formato', function () {
    $device = Device::factory()->create();

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Devices::class)
        ->call('editSecurity', $device->id)
        ->call('saveSecurity')
        ->assertHasErrors(['security.pin' => 'required'])
        ->set('security.pin', '12')
        ->set('security.pin_confirmation', '12')
        ->call('saveSecurity')
        ->assertHasErrors(['security.pin' => 'digits_between'])
        ->set('security.pin', '123456')
        ->set('security.pin_confirmation', '654321')
        ->call('saveSecurity')
        ->assertHasErrors(['security.pin' => 'confirmed']);
});

it('si ya tiene clave, dejarla en blanco la mantiene', function () {
    $device = Device::factory()->create();
    $device->setUnlockPin('1111');
    $device->save();
    $hash = $device->unlock_pin_hash;

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Devices::class)
        ->call('editSecurity', $device->id)
        ->set('security.block_safe_boot', true)
        ->call('saveSecurity')
        ->assertHasNoErrors();

    expect($device->fresh()->unlock_pin_hash)->toBe($hash)
        ->and($device->fresh()->block_safe_boot)->toBeTrue();
});

it('liberar quita las restricciones y pide la liberación al terminal', function () {
    $device = Device::factory()->create(['block_screen_capture' => true, 'block_uninstall' => true]);

    Livewire::actingAs(makeUser('administrador'))
        ->test(Devices::class)
        ->call('release', $device->id)
        ->assertDispatched('toast');

    $device->refresh();

    expect($device->release_requested)->toBeTrue()
        ->and($device->block_screen_capture)->toBeFalse()
        ->and($device->block_uninstall)->toBeFalse()
        ->and($device->protectionState())->toBe('pending');
});

it('aplicar a todos copia las restricciones a los terminales activos, no la clave', function () {
    $source = Device::factory()->create();
    $other = Device::factory()->create();
    $inactive = Device::factory()->inactive()->create();

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Devices::class)
        ->call('editSecurity', $source->id)
        ->set('security.block_screen_capture', true)
        ->set('security.block_factory_reset', true)
        ->call('applySecurityToAll');

    expect($other->fresh()->block_screen_capture)->toBeTrue()
        ->and($other->fresh()->block_factory_reset)->toBeTrue()
        ->and($other->fresh()->hasUnlockPin())->toBeFalse()
        ->and($inactive->fresh()->block_screen_capture)->toBeFalse();
});

it('un chofer no puede tocar la protección', function () {
    $device = Device::factory()->create();

    Livewire::actingAs(makeUser('chofer'))
        ->test(Devices::class)
        ->assertForbidden();

    expect($device->fresh()->block_screen_capture)->toBeFalse();
});

it('el estado de protección refleja lo reportado por el terminal', function () {
    $device = Device::factory()->create(['block_screen_capture' => true]);
    expect($device->protectionState())->toBe('pending');

    $device->update(['reported_device_owner' => false, 'reported_policies' => []]);
    expect($device->protectionState())->toBe('unmanaged');

    $device->update([
        'reported_device_owner' => true,
        'reported_policies' => ['block_screen_capture' => true, 'block_uninstall' => false, 'block_factory_reset' => false, 'block_safe_boot' => false],
    ]);
    expect($device->protectionState())->toBe('protected');
});

// --- Descarga de la APK tracker (docs/24) ---

it('administrador descarga la APK publicada, con la versión en el nombre', function () {
    Storage::fake('local');
    Storage::disk('local')->put(TrackerApk::FILE, 'APK-BYTES');
    Storage::disk('local')->put(TrackerApk::META, json_encode(['version' => '1.1.0', 'built_at' => '2026-11-25T10:00:00+01:00']));

    $this->actingAs(makeUser('administrador'))
        ->get(route('devices.apk'))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.android.package-archive')
        ->assertDownload('servalillo-tracker-1.1.0.apk');
});

it('sin APK publicada la descarga da 404 y la pantalla lo indica', function () {
    Storage::fake('local');

    $this->actingAs(makeUser('mantenimiento'))->get(route('devices.apk'))->assertNotFound();

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Devices::class)
        ->assertSee('Aún no se ha publicado ninguna APK')
        ->assertDontSee('Descargar APK del tracker');
});

it('la pantalla muestra el botón de descarga con la versión', function () {
    Storage::fake('local');
    Storage::disk('local')->put(TrackerApk::FILE, 'APK-BYTES');
    Storage::disk('local')->put(TrackerApk::META, json_encode(['version' => '1.1.0', 'built_at' => '2026-11-25T10:00:00+01:00']));

    Livewire::actingAs(makeUser('administrador'))
        ->test(Devices::class)
        ->assertSee('Descargar APK del tracker')
        ->assertSee('Versión 1.1.0');
});

it('un chofer no puede descargar la APK', function () {
    Storage::fake('local');
    Storage::disk('local')->put(TrackerApk::FILE, 'APK-BYTES');

    $this->actingAs(makeUser('chofer'))->get(route('devices.apk'))->assertForbidden();
});
