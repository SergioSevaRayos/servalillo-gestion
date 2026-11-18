<?php

namespace App\Livewire\Fleet;

use App\Enums\AlertType;
use App\Enums\RouteStatus;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\GpsPosition;
use App\Models\RouteDay;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dónde está la flota ahora mismo: la última posición GPS de cada dispositivo activo con
 * chofer asignado. Se refresca con `wire:poll` (no hay Echo cableado) y el mapa Leaflet
 * (`Alpine.data('fleetMap')`) mueve los marcadores sin recrearse.
 */
#[Layout('layouts.app')]
class Map extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->can('devices.manage'), 403);
    }

    /**
     * Minutos sin recibir posición a partir de los cuales un camión se pinta como "sin señal":
     * el mismo umbral que el aviso "Dispositivo sin señal GPS" de /avisos (una sola fuente).
     */
    public function staleMinutes(): int
    {
        return (int) (AlertRule::forType(AlertType::GpsSilence)->threshold ?? AlertType::GpsSilence->defaultThreshold());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function fleet(): array
    {
        $stale = $this->staleMinutes();

        $devices = Device::query()
            ->with('driver.user')
            ->where('is_active', true)
            ->whereNotNull('driver_id')
            ->get();

        // Ruta de hoy de cada chofer (camión + estado) en una sola consulta.
        $routes = RouteDay::query()
            ->with('truck')
            ->forDate(today())
            ->whereIn('driver_id', $devices->pluck('driver_id'))
            ->get()
            ->keyBy('driver_id');

        return $devices->map(function (Device $device) use ($routes, $stale) {
            // Índice (device_id, recorded_at): última posición sin recorrer la tabla.
            $last = GpsPosition::query()
                ->where('device_id', $device->id)
                ->orderByDesc('recorded_at')
                ->first(['latitude', 'longitude', 'speed_mps', 'battery_level', 'recorded_at']);

            $route = $routes->get($device->driver_id);
            $recordedAt = $last?->recorded_at;
            $minutes = $recordedAt ? (int) $recordedAt->diffInMinutes(now(), true) : null;

            return [
                'id' => $device->id,
                'driver' => $device->driver?->user?->name ?? $device->label,
                'label' => $device->label,
                'truck' => $route?->truck?->code,
                'route_status' => $route?->status instanceof RouteStatus ? $route->status->label() : null,
                'lat' => $last ? (float) $last->latitude : null,
                'lng' => $last ? (float) $last->longitude : null,
                'speed_kmh' => $last?->speed_mps !== null ? (int) round($last->speed_mps * 3.6) : null,
                'battery' => $last?->battery_level,
                'recorded_at' => $recordedAt?->toIso8601String(),
                'minutes_ago' => $minutes,
                // fresh = reporta | stale = sin señal reciente | none = nunca ha enviado nada
                'state' => $last === null ? 'none' : ($minutes > $stale ? 'stale' : 'fresh'),
            ];
        })->sortBy('driver')->values()->all();
    }

    /** Lo llama el poll: recalcula y avisa al mapa (que está en wire:ignore) para mover los pins. */
    public function refreshMap(): void
    {
        unset($this->fleet);
        $this->dispatch('fleet-map-updated', fleet: $this->fleet);
    }

    public function render()
    {
        return view('livewire.fleet.map', ['stale' => $this->staleMinutes()]);
    }
}
