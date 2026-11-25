<?php

use App\Models\Device;
use App\Services\DevicePinHasher;
use App\Services\TrackerApk;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use OwenIt\Auditing\Models\Audit;

/*
| Protección del terminal del chofer (docs/24): GET /api/device envía las restricciones y la
| clave (hash) y POST /api/device/state recibe lo que el terminal tiene aplicado.
*/

function stateToken(Device $device): string
{
    return $device->createToken('tracker', ['gps:ingest'])->plainTextToken;
}

it('GET /api/device incluye las restricciones pedidas y sin clave devuelve unlock null', function () {
    $device = Device::factory()->create(['block_screen_capture' => true]);

    $this->withToken(stateToken($device))
        ->getJson('/api/device')
        ->assertOk()
        ->assertJsonPath('policies.block_screen_capture', true)
        ->assertJsonPath('policies.block_uninstall', false)
        ->assertJsonPath('policies.block_factory_reset', false)
        ->assertJsonPath('policies.block_safe_boot', false)
        ->assertJsonPath('policies.release', false)
        ->assertJsonPath('unlock', null);
});

it('GET /api/device manda el hash y la sal de la clave, nunca la clave', function () {
    $device = Device::factory()->create();
    $device->setUnlockPin('2468');
    $device->save();

    $res = $this->withToken(stateToken($device))->getJson('/api/device')->assertOk();

    expect($res->json('unlock.hash'))->toBe($device->unlock_pin_hash)
        ->and($res->json('unlock.salt'))->toBe($device->unlock_pin_salt)
        ->and($res->json('unlock.iterations'))->toBe(DevicePinHasher::ITERATIONS)
        ->and($res->getContent())->not->toContain('2468');
});

it('guarda el estado reportado por el terminal', function () {
    $device = Device::factory()->create(['block_screen_capture' => true]);

    $this->withToken(stateToken($device))
        ->postJson('/api/device/state', [
            'device_owner' => true,
            'applied' => ['block_screen_capture' => true, 'block_uninstall' => false],
            'app_version' => '1.1.0',
        ])
        ->assertOk();

    $device->refresh();

    expect($device->reported_device_owner)->toBeTrue()
        ->and($device->reported_policies['block_screen_capture'])->toBeTrue()
        ->and($device->reported_policies['block_safe_boot'])->toBeFalse()
        ->and($device->policies_reported_at)->not->toBeNull()
        ->and($device->app_version)->toBe('1.1.0')
        ->and($device->protectionState())->toBe('protected');
});

it('cierra la liberación cuando el terminal deja de ser administrador', function () {
    $device = Device::factory()->create(['release_requested' => true]);

    $this->withToken(stateToken($device))
        ->postJson('/api/device/state', ['device_owner' => false, 'applied' => []])
        ->assertOk();

    $device->refresh();

    expect($device->release_requested)->toBeFalse()
        ->and($device->released_at)->not->toBeNull()
        ->and($device->protectionState())->toBe('released');
});

it('no cierra la liberación si el terminal sigue siendo administrador', function () {
    $device = Device::factory()->create(['release_requested' => true]);

    $this->withToken(stateToken($device))
        ->postJson('/api/device/state', ['device_owner' => true, 'applied' => []])
        ->assertOk();

    expect($device->fresh()->release_requested)->toBeTrue();
});

it('valida el cuerpo del reporte', function () {
    $device = Device::factory()->create();

    $this->withToken(stateToken($device))
        ->postJson('/api/device/state', ['applied' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['device_owner', 'applied']);
});

it('rechaza un token de usuario o sin token', function () {
    $this->postJson('/api/device/state', ['device_owner' => true, 'applied' => []])->assertUnauthorized();

    Sanctum::actingAs(makeUser('chofer'), ['gps:ingest']);
    $this->postJson('/api/device/state', ['device_owner' => true, 'applied' => []])->assertForbidden();
});

it('la clave nunca se audita', function () {
    // En consola (tests) la auditoría está apagada por defecto: se enciende para comprobarlo.
    config(['audit.console' => true]);
    $this->actingAs(makeUser('mantenimiento'));
    $device = Device::factory()->create();
    $device->setUnlockPin('9999');
    $device->block_screen_capture = true;
    $device->save();

    $audits = Audit::query()->where('auditable_id', $device->id)->get();
    expect($audits)->not->toBeEmpty();

    $values = $audits
        ->flatMap(fn ($a) => array_keys(array_merge((array) $a->old_values, (array) $a->new_values)));

    expect($values)->not->toContain('unlock_pin_hash')->not->toContain('unlock_pin_salt');
});

// --- Actualización automática de la APK (docs/24) ---

it('sin APK publicada app_update es null', function () {
    Storage::fake('local');
    $device = Device::factory()->create();

    $this->withToken(stateToken($device))->getJson('/api/device')
        ->assertOk()
        ->assertJsonPath('app_update', null);
});

it('con APK publicada anuncia su versionCode y su huella', function () {
    Storage::fake('local');
    Storage::disk('local')->put(TrackerApk::FILE, 'APK-BYTES');
    Storage::disk('local')->put(TrackerApk::META, json_encode([
        'version' => '1.3.0', 'version_code' => 3, 'sha256' => hash('sha256', 'APK-BYTES'),
    ]));
    $device = Device::factory()->create();

    $this->withToken(stateToken($device))->getJson('/api/device')
        ->assertOk()
        ->assertJsonPath('app_update.version', '1.3.0')
        ->assertJsonPath('app_update.version_code', 3)
        ->assertJsonPath('app_update.sha256', hash('sha256', 'APK-BYTES'))
        ->assertJsonPath('app_update.size', 9);
});

it('sin versionCode en el tracker.json no anuncia actualización', function () {
    Storage::fake('local');
    Storage::disk('local')->put(TrackerApk::FILE, 'APK-BYTES');
    Storage::disk('local')->put(TrackerApk::META, json_encode(['version' => '1.2.0', 'sha256' => 'x']));
    $device = Device::factory()->create();

    $this->withToken(stateToken($device))->getJson('/api/device')->assertJsonPath('app_update', null);
});

it('el dispositivo descarga la APK con su token', function () {
    Storage::fake('local');
    Storage::disk('local')->put(TrackerApk::FILE, 'APK-BYTES');
    $device = Device::factory()->create();

    $this->withToken(stateToken($device))->get('/api/device/apk')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.android.package-archive');
});

it('la descarga de la APK por API exige token de dispositivo', function () {
    Storage::fake('local');
    Storage::disk('local')->put(TrackerApk::FILE, 'APK-BYTES');

    $this->getJson('/api/device/apk')->assertUnauthorized();

    Sanctum::actingAs(makeUser('administrador'), ['gps:ingest']);
    $this->getJson('/api/device/apk')->assertForbidden();
});
