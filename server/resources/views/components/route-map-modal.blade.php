{{--
    "Ver recorrido" (Bloque 13). Modal con un mapa Leaflet de las paradas de la ruta y su
    trazado. El componente Livewire (Board / Chofer\Today) emite `open-route-map` con
    { stops, meta, skipped }; el Alpine `routeMap` (resources/js/app.js) abre este modal y
    monta el mapa cuando el contenedor ya tiene tamaño.
--}}
<x-modal name="route-map" max-width="4xl">
    <div class="p-4" x-data="routeMap()" x-on:open-route-map.window="open($event.detail)">
        <div class="mb-3 flex items-center justify-between gap-3">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Recorrido') }}</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400" x-text="summary" x-show="summary"></p>
        </div>

        <div x-ref="map" wire:ignore
            class="h-[65vh] w-full overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700"></div>

        <p class="mt-2 text-sm font-medium text-amber-600 dark:text-amber-400" x-text="approachNote" x-show="approachNote"></p>
        <p class="mt-1 text-xs text-slate-400" x-text="skippedNote" x-show="skippedNote"></p>

        <div class="mt-4 flex justify-end">
            <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
        </div>
    </div>
</x-modal>
