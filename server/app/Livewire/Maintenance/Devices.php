<?php

namespace App\Livewire\Maintenance;

use App\Models\Device;
use App\Models\Driver;
use App\Models\GpsPosition;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Panel de Mantenimiento (Bloque 10): dispositivos con la APK "tracker" instalada.
 * El técnico asigna cada dispositivo a un chofer, revoca su acceso o lo desactiva.
 */
#[Layout('layouts.app')]
class Devices extends Component
{
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

    /** Revoca los tokens: la APK tendrá que volver a enrolarse. */
    public function revoke(int $deviceId): void
    {
        $device = Device::findOrFail($deviceId);
        $this->authorize('update', $device);

        $device->tokens()->delete();

        $this->dispatch('toast', message: 'Acceso revocado. El dispositivo tendrá que volver a enrolarse.', variant: 'success');
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
        return view('livewire.maintenance.devices');
    }
}
