<?php

namespace App\Livewire\Maintenance;

use App\Models\Device;
use App\Models\Driver;
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

        if ($driverId && Device::where('driver_id', $driverId)->where('id', '!=', $device->id)->exists()) {
            $this->dispatch('toast', message: 'Ese chofer ya tiene un dispositivo asignado.', variant: 'warning');

            return;
        }

        $device->update(['driver_id' => $driverId]);
        unset($this->devices);

        $this->dispatch('toast', message: 'Dispositivo actualizado.', variant: 'success');
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
