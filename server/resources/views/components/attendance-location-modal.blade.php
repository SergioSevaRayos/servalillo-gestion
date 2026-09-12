{{--
    Fichaje (Bloque 18): "desde dónde han fichado" — mapa de solo lectura con los puntos de
    entrada/salida y la geovalla de la persona (círculo gris discontinuo de fondo).
    `App\Livewire\Attendance\Manage::viewLocation()` emite `open-attendance-location` con
    { person, date, geofence, in, out }; el Alpine `attendanceLocationMap` (resources/js/app.js)
    abre este modal y monta el mapa cuando el contenedor ya tiene tamaño.
--}}
<x-modal name="attendance-location-view" max-width="2xl">
    <div class="p-4" x-data="attendanceLocationMap()" x-on:open-attendance-location.window="open($event.detail)">
        <div class="mb-3">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white" x-text="title"></h3>
            <p class="text-sm text-slate-500 dark:text-slate-400" x-text="subtitle"></p>
        </div>

        <template x-if="empty">
            <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                {{ __('Este fichaje no tiene coordenadas registradas (se hizo sin ubicación).') }}
            </p>
        </template>

        <div x-show="! empty" x-ref="map" wire:ignore
            class="h-[50vh] w-full overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700"></div>

        <p class="mt-2 text-xs text-slate-400">
            {{ __('Círculo discontinuo = zona permitida de esta persona. Verde = entrada, morado = salida.') }}
        </p>

        <div class="mt-4 flex justify-end">
            <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
        </div>
    </div>
</x-modal>
