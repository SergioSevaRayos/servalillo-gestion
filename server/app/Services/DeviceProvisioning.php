<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

/**
 * Alta de un móvil por código QR (Android Enterprise "QR provisioning", docs/24).
 *
 * En la pantalla de bienvenida de un móvil recién restablecido se toca 6 veces y se escanea este
 * QR: Android descarga la APK tracker del enlace firmado, comprueba su huella (sha256) y la deja
 * como Device Owner. Así no hace falta `adb` ni ordenador.
 */
class DeviceProvisioning
{
    public const COMPONENT = 'es.servalillo.tracker/es.servalillo.device_policy.AdminReceiver';

    /** Validez del enlace de descarga que lleva el QR. */
    public const LINK_HOURS = 24;

    public function __construct(private TrackerApk $apk) {}

    public function available(): bool
    {
        return $this->apk->exists();
    }

    /**
     * Enlace temporal firmado: el móvil aún no tiene sesión, y la APK lleva dentro el secreto de
     * enrolamiento, así que no se publica en abierto.
     */
    public function downloadUrl(Carbon $expiresAt): string
    {
        return URL::temporarySignedRoute('devices.provision.apk', $expiresAt);
    }

    /**
     * SHA-256 del fichero en base64url sin relleno (formato de PROVISIONING_DEVICE_ADMIN_PACKAGE_CHECKSUM).
     * Cacheado por fecha y tamaño del fichero: con 60 MB no se recalcula en cada render.
     */
    public function packageChecksum(): string
    {
        $path = $this->apk->path();
        $key = 'tracker-apk-checksum:'.filemtime($path).':'.filesize($path);

        return Cache::remember($key, now()->addDays(30), fn () => rtrim(strtr(base64_encode(hash_file('sha256', $path, true)), '+/', '-_'), '='));
    }

    /**
     * Contenido del QR. Con Wi-Fi, el móvil se conecta solo durante el alta.
     *
     * @return array<string, mixed>
     */
    public function payload(Carbon $expiresAt, ?string $wifiSsid = null, ?string $wifiPassword = null): array
    {
        $payload = [
            'android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME' => self::COMPONENT,
            'android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION' => $this->downloadUrl($expiresAt),
            'android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_CHECKSUM' => $this->packageChecksum(),
            // Sin esto Android desactiva las apps del sistema (Chrome incluido), y el chofer usa la web en Chrome.
            'android.app.extra.PROVISIONING_LEAVE_ALL_SYSTEM_APPS_ENABLED' => true,
            'android.app.extra.PROVISIONING_SKIP_ENCRYPTION' => false,
        ];

        if (filled($wifiSsid)) {
            $payload['android.app.extra.PROVISIONING_WIFI_SSID'] = $wifiSsid;
            $payload['android.app.extra.PROVISIONING_WIFI_SECURITY_TYPE'] = filled($wifiPassword) ? 'WPA' : 'NONE';
            if (filled($wifiPassword)) {
                $payload['android.app.extra.PROVISIONING_WIFI_PASSWORD'] = $wifiPassword;
            }
        }

        return $payload;
    }

    public function qrSvg(array $payload, int $size = 320): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd));

        return $writer->writeString(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
