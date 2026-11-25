<?php

namespace App\Livewire\Devices;

use App\Livewire\Forms\DeviceLostModeForm;
use App\Livewire\Forms\DeviceProvisioningForm;
use App\Livewire\Forms\DeviceSecurityForm;
use App\Models\Device;
use App\Models\Driver;
use App\Models\GpsPosition;
use App\Services\DeviceProvisioning;
use App\Services\LostDeviceService;
use App\Services\TrackerApk;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dispositivos con la APK "tracker" instalada (Bloque 10). Se asigna cada dispositivo a un chofer,
 * se revoca su acceso o se desactiva, y se gestiona la protección del terminal (docs/24).
 *
 * Va en el grupo Gestión (/dispositivos), no bajo /mantenimiento: lo usan administrador y
 * mantenimiento (permiso `devices.manage`).
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public DeviceSecurityForm $security;

    public DeviceProvisioningForm $provisioning;

    public DeviceLostModeForm $lost;

    /** Caducidad del enlace del QR de alta abierto (ISO 8601), o null si no hay ninguno. */
    public ?string $provisionExpiresAt = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Device::class);
    }

    #[Computed]
    public function devices(): Collection
    {
        return Device::query()
            ->with('driver.user')
            ->withCount('positions')
            ->orderByRaw('last_seen_at DESC NULLS LAST')
            ->get();
    }

    /** La APK publicada para descargar (versión, fecha, tamaño) o null. */
    #[Computed]
    public function apk(): ?array
    {
        return app(TrackerApk::class)->info();
    }

    /** Abre el QR de alta de un móvil nuevo (docs/24), con un enlace válido LINK_HOURS horas. */
    public function openProvisioning(): void
    {
        $this->authorize('create', Device::class);

        if (! app(DeviceProvisioning::class)->available()) {
            $this->dispatch('toast', message: 'Todavía no se ha publicado ninguna APK: no se puede generar el QR.', variant: 'warning');

            return;
        }

        $this->provisioning->reset();
        $this->provisioning->resetValidation();
        $this->provisionExpiresAt = now()->addHours(DeviceProvisioning::LINK_HOURS)->toIso8601String();
        $this->dispatch('open-modal', 'device-provisioning');
    }

    public function updatedProvisioning(): void
    {
        $this->provisioning->validate();
    }

    /** SVG del QR de alta, o null si no hay ninguno abierto o la Wi-Fi no valida. */
    #[Computed]
    public function provisioningQr(): ?string
    {
        if ($this->provisionExpiresAt === null || $this->getErrorBag()->hasAny(['provisioning.wifi_ssid', 'provisioning.wifi_password'])) {
            return null;
        }

        $service = app(DeviceProvisioning::class);
        if (! $service->available()) {
            return null;
        }

        return $service->qrSvg($service->payload(
            Carbon::parse($this->provisionExpiresAt),
            $this->provisioning->wifi_ssid ?: null,
            $this->provisioning->wifi_password ?: null,
        ));
    }

    #[Computed]
    public function drivers(): Collection
    {
        return Driver::query()
            ->with('user')
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (Driver $d) => $d->user?->name)
            ->values();
    }

    /** Asigna (o desasigna, con $driverId vacío) el dispositivo a un chofer. */
    public function assign(int $deviceId, ?string $driverId): void
    {
        $device = Device::findOrFail($deviceId);
        $this->authorize('update', $device);

        $driverId = $driverId ? (int) $driverId : null;

        $holder = $driverId
            ? Device::where('driver_id', $driverId)->where('id', '!=', $device->id)->first()
            : null;

        if ($holder) {
            unset($this->devices);
            $this->dispatch('toast',
                message: "Ese chofer ya tiene un dispositivo asignado ({$holder->label}). Quítaselo primero.",
                variant: 'warning',
            );

            return;
        }

        $device->update(['driver_id' => $driverId]);
        unset($this->devices);

        $this->dispatch('toast', message: 'Dispositivo actualizado.', variant: 'success');
    }

    /**
     * Abre el mapa con la última ubicación conocida del dispositivo y un rastro de
     * las posiciones recientes. Emite `open-device-map` para el Alpine `deviceMap`.
     */
    public function locate(int $deviceId): void
    {
        $device = Device::with('driver.user')->findOrFail($deviceId);
        $this->authorize('view', $device);

        $positions = GpsPosition::query()
            ->where('device_id', $device->id)
            ->orderByDesc('recorded_at')
            ->limit(60)
            ->get(['latitude', 'longitude', 'accuracy_m', 'speed_mps', 'heading_deg', 'battery_level', 'recorded_at']);

        if ($positions->isEmpty()) {
            $this->dispatch('toast', message: 'Este dispositivo aún no ha enviado ninguna posición.', variant: 'warning');

            return;
        }

        $last = $positions->first();

        $this->dispatch('open-device-map', ...[
            'label' => $device->label,
            'driver' => $device->driver?->user?->name,
            'last' => [
                'lat' => (float) $last->latitude,
                'lng' => (float) $last->longitude,
                'accuracy_m' => $last->accuracy_m !== null ? (float) $last->accuracy_m : null,
                'speed_mps' => $last->speed_mps !== null ? (float) $last->speed_mps : null,
                'heading_deg' => $last->heading_deg !== null ? (float) $last->heading_deg : null,
                'battery_level' => $last->battery_level,
                'recorded_at' => $last->recorded_at?->toIso8601String(),
            ],
            // Rastro en orden cronológico (el más antiguo primero) para dibujar la línea.
            'trail' => $positions->reverse()->values()->map(fn (GpsPosition $p) => [
                (float) $p->latitude,
                (float) $p->longitude,
            ])->all(),
        ]);
    }

    public function toggleActive(int $deviceId): void
    {
        $device = Device::findOrFail($deviceId);
        $this->authorize('update', $device);

        $device->update(['is_active' => ! $device->is_active]);
        unset($this->devices);

        $this->dispatch('toast',
            message: $device->is_active ? 'Dispositivo reactivado.' : 'Dispositivo desactivado.',
            variant: 'success',
        );
    }

    /** Abre el modal "Seguridad" del terminal. */
    public function editSecurity(int $deviceId): void
    {
        $device = Device::findOrFail($deviceId);
        $this->authorize('update', $device);

        $this->security->setDevice($device);
        $this->dispatch('open-modal', 'device-security');
    }

    public function saveSecurity(): void
    {
        $device = $this->security->editing;
        abort_unless($device instanceof Device, 404);
        $this->authorize('update', $device);

        $this->security->save();
        unset($this->devices);

        $this->dispatch('close-modal', 'device-security');
        $this->dispatch('toast', message: 'Protección guardada. El terminal la aplicará en unos minutos.', variant: 'success');
    }

    /**
     * Pide al terminal que quite todas las restricciones y deje de ser administrador del
     * dispositivo. Necesario para reutilizar o retirar un móvil (el reset de fábrica está bloqueado).
     */
    public function release(int $deviceId): void
    {
        $device = Device::findOrFail($deviceId);
        $this->authorize('update', $device);

        $device->forceFill([
            'block_screen_capture' => false,
            'block_uninstall' => false,
            'block_factory_reset' => false,
            'block_safe_boot' => false,
            'release_requested' => true,
        ])->save();
        unset($this->devices);

        $this->dispatch('close-modal', 'device-security');
        $this->dispatch('toast', message: 'Liberación pedida. El terminal la aplicará en la próxima sincronización.', variant: 'success');
    }

    /** Abre el modal del modo perdido (robo o pérdida, docs/24). */
    public function editLostMode(int $deviceId): void
    {
        $device = Device::with('driver.user')->findOrFail($deviceId);
        $this->authorize('update', $device);

        $this->lost->setDevice($device);
        $this->dispatch('open-modal', 'device-lost');
    }

    public function enableLostMode(): void
    {
        $device = $this->lost->editing;
        abort_unless($device instanceof Device, 404);
        $this->authorize('update', $device);

        $sessions = $this->lost->save();
        unset($this->devices);

        $this->dispatch('close-modal', 'device-lost');
        $this->dispatch('toast',
            message: 'Modo perdido activado. El móvil se bloqueará en cuanto tenga conexión'
                .($sessions > 0 ? " y se han cerrado {$sessions} sesiones web del chofer." : '.'),
            variant: 'success',
        );
    }

    public function disableLostMode(int $deviceId): void
    {
        $device = Device::findOrFail($deviceId);
        $this->authorize('update', $device);

        app(LostDeviceService::class)->disable($device);
        unset($this->devices);

        $this->dispatch('toast', message: 'Modo perdido desactivado. El mensaje desaparecerá de la pantalla de bloqueo en la próxima sincronización.', variant: 'success');
    }

    public function delete(int $deviceId): void
    {
        $device = Device::findOrFail($deviceId);
        $this->authorize('delete', $device);

        $device->tokens()->delete();
        $device->delete();
        unset($this->devices);

        $this->dispatch('toast', message: 'Dispositivo eliminado.', variant: 'success');
    }

    public function render()
    {
        return view('livewire.devices.index');
    }
}
