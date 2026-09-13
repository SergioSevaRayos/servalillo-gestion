<?php

namespace App\Livewire\Maintenance;

use App\Models\CompanySetting;
use App\Services\GeocodingService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Bloque 18 ampliación (2026-09-13): ajustes generales editables sin tocar el .env ni
 * desplegar — pensado para las pruebas iniciales (fijar la base en un domicilio
 * particular) y para poder ajustarlos luego sin depender de un despliegue.
 * `App\Providers\AppServiceProvider::boot()` es quien realmente aplica estos valores a
 * `config('servalillo.*')` en cada petición; aquí solo se guardan y se aplican también
 * en caliente para que el resto de este mismo request (y el guardado) ya los vea.
 */
#[Layout('layouts.app')]
class Settings extends Component
{
    public string $base_latitude = '';

    public string $base_longitude = '';

    public string $unplanned_stop_minutes = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->isMaintenance(), 403);

        $setting = CompanySetting::current();

        $this->base_latitude = $setting->base_latitude !== null
            ? (string) $setting->base_latitude
            : (string) config('servalillo.base.latitude');

        $this->base_longitude = $setting->base_longitude !== null
            ? (string) $setting->base_longitude
            : (string) config('servalillo.base.longitude');

        $this->unplanned_stop_minutes = $setting->unplanned_stop_minutes !== null
            ? (string) $setting->unplanned_stop_minutes
            : (string) ((int) config('servalillo.dwell.unplanned_stop_min_seconds') / 60);
    }

    /** Buscador de direcciones del mapa de la base (<x-ui.geofence-map>). */
    public function searchAddress(string $query): array
    {
        return app(GeocodingService::class)->search($query);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'base_latitude' => ['required', 'numeric', 'between:-90,90'],
            'base_longitude' => ['required', 'numeric', 'between:-180,180'],
            'unplanned_stop_minutes' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        $setting = CompanySetting::current();
        $setting->update($validated);

        config([
            'servalillo.base.latitude' => (float) $validated['base_latitude'],
            'servalillo.base.longitude' => (float) $validated['base_longitude'],
            'servalillo.dwell.unplanned_stop_min_seconds' => (int) $validated['unplanned_stop_minutes'] * 60,
        ]);

        $this->dispatch('toast', message: 'Ajustes actualizados.', variant: 'success');
    }

    public function render()
    {
        return view('livewire.maintenance.settings');
    }
}
