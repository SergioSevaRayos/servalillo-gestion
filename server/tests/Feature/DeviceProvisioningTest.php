<?php

use App\Livewire\Devices\Index as Devices;
use App\Services\DeviceProvisioning;
use App\Services\TrackerApk;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
| Alta de un móvil por código QR (docs/24).
*/

function publishFakeApk(string $bytes = 'APK-BYTES'): void
{
    Storage::fake('local');
    Storage::disk('local')->put(TrackerApk::FILE, $bytes);
    Storage::disk('local')->put(TrackerApk::META, json_encode(['version' => '1.2.0']));
}

it('el contenido del QR lleva el componente, el enlace firmado y la huella de la APK', function () {
    publishFakeApk('contenido-apk');

    $payload = app(DeviceProvisioning::class)->payload(now()->addDay());

    expect($payload['android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME'])
        ->toBe('es.servalillo.tracker/es.servalillo.device_policy.AdminReceiver')
        ->and($payload['android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_CHECKSUM'])
        ->toBe(rtrim(strtr(base64_encode(hash('sha256', 'contenido-apk', true)), '+/', '-_'), '='))
        ->and($payload['android.app.extra.PROVISIONING_LEAVE_ALL_SYSTEM_APPS_ENABLED'])->toBeTrue()
        ->and($payload)->not->toHaveKey('android.app.extra.PROVISIONING_WIFI_SSID');

    $url = $payload['android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION'];
    expect($url)->toContain('/alta-movil/apk')->toContain('signature=');
});

it('con Wi-Fi la añade al QR', function () {
    publishFakeApk();

    $payload = app(DeviceProvisioning::class)->payload(now()->addDay(), 'Nave', 'secreta123');

    expect($payload['android.app.extra.PROVISIONING_WIFI_SSID'])->toBe('Nave')
        ->and($payload['android.app.extra.PROVISIONING_WIFI_PASSWORD'])->toBe('secreta123')
        ->and($payload['android.app.extra.PROVISIONING_WIFI_SECURITY_TYPE'])->toBe('WPA');
});

it('el enlace firmado descarga la APK sin sesión', function () {
    publishFakeApk();

    $this->get(URL::temporarySignedRoute('devices.provision.apk', now()->addHour()))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.android.package-archive');
});

it('sin firma o caducado no descarga nada', function () {
    publishFakeApk();

    $this->get(route('devices.provision.apk'))->assertForbidden();

    $expired = URL::temporarySignedRoute('devices.provision.apk', now()->addHour());
    $this->travel(2)->hours();
    $this->get($expired)->assertForbidden();
});

it('administrador abre el QR de alta', function () {
    publishFakeApk();

    Livewire::actingAs(makeUser('administrador'))
        ->test(Devices::class)
        ->call('openProvisioning')
        ->assertDispatched('open-modal', 'device-provisioning')
        ->assertSet('provisionExpiresAt', fn ($v) => $v !== null)
        ->assertSee('<svg', false)
        ->assertSee('Dar de alta un móvil');
});

it('sin APK publicada avisa en vez de abrir el QR', function () {
    Storage::fake('local');

    Livewire::actingAs(makeUser('mantenimiento'))
        ->test(Devices::class)
        ->call('openProvisioning')
        ->assertDispatched('toast', variant: 'warning')
        ->assertNotDispatched('open-modal');
});

it('valida la contraseña de la Wi-Fi y oculta el QR mientras no valga', function () {
    publishFakeApk();

    $component = Livewire::actingAs(makeUser('administrador'))
        ->test(Devices::class)
        ->call('openProvisioning')
        ->set('provisioning.wifi_ssid', 'Nave')
        ->set('provisioning.wifi_password', 'corta')
        ->assertHasErrors(['provisioning.wifi_password' => 'min']);

    expect($component->instance()->provisioningQr)->toBeNull();

    $component->set('provisioning.wifi_password', 'suficiente')
        ->assertHasNoErrors();
    expect($component->instance()->provisioningQr)->toContain('<svg');
});

it('un chofer no puede generar el QR', function () {
    publishFakeApk();

    Livewire::actingAs(makeUser('chofer'))
        ->test(Devices::class)
        ->assertForbidden();
});
