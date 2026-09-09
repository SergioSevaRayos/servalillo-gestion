{{--
    "Localizar dispositivo" (Bloque 11). Modal con un mapa Leaflet centrado en la última
    ubicación conocida de un dispositivo tracker, con un rastro de las posiciones recientes.
    `App\Livewire\Maintenance\Devices::locate()` emite `open-device-map` con
    { label, driver, last, trail }; el Alpine `deviceMap` (resources/js/app.js) abre este
    modal y monta el mapa cuando el contenedor ya tiene tamaño.
--}}
<x-modal name="device-map" max-width="4xl">
    <div class="p-4" x-data="deviceMap()" x-on:open-device-map.window="open($event.detail)">
        <div class="mb-3 flex items-start justify-between gap-3">
            <div>
                <h3 class="text-lg font-medium text-slate-900 dark:text-white" x-text="title"></h3>
                <p class="text-sm text-slate-500 dark:text-slate-400" x-text="subtitle" x-show="subtitle"></p>
            </div>
            <p class="whitespace-nowrap text-sm text-slate-500 dark:text-slate-400" x-text="age" x-show="age"></p>
        </div>

        <div x-ref="map" wire:ignore
            class="h-[65vh] w-full overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700"></div>

        <p class="mt-2 text-xs text-slate-400" x-text="detail" x-show="detail"></p>

        <div class="mt-4 flex justify-end">
            <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
        </div>
    </div>
</x-modal>
