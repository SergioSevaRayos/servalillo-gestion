<?php

use App\Models\Device;
use App\Services\DevicePinHasher;
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
