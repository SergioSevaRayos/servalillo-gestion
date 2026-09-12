@props([
    'latPath',
    'lngPath',
    'radiusPath',
    'searchMethod',
    'lat' => null,
    'lng' => null,
    'radius' => null,
    'baseLat',
    'baseLng',
    'defaultRadius',
])

{{--
    Fichaje (Bloque 18): geovalla — mapa Y dígitos a la vez, sincronizados en ambos sentidos
    (arrastrar en el mapa actualiza los campos; escribir un número mueve el mapa), más un
    buscador de direcciones para no tener que localizar el punto a ojo. Pin verde arrastrable
    para el centro, asa ámbar arrastrable para el radio (siempre al este del centro, a la
    distancia = radio). Escribe directamente en las rutas de Livewire dadas
    (`$wire.set(latPath, ...)`, etc.) en vez de por `wire:model`, porque los tres campos y el mapa
    comparten el mismo estado reactivo de Alpine — ver Alpine.data('geofenceMap') en
    resources/js/app.js. `searchMethod` es el nombre del método del componente Livewire padre
    (`Users\Index::searchAddress()` / `Drivers\Index::searchAddress()`) que hace de proxy a
    Nominatim — nunca se llama a la API externa directo desde el navegador.
--}}
<div
    wire:ignore
    x-data="geofenceMap({
        lat: {{ $lat !== null ? (float) $lat : (float) $baseLat }},
        lng: {{ $lng !== null ? (float) $lng : (float) $baseLng }},
        radius: {{ $radius !== null ? (int) $radius : (int) $defaultRadius }},
        latPath: @js($latPath),
        lngPath: @js($lngPath),
        radiusPath: @js($radiusPath),
        searchMethod: @js($searchMethod),
    })"
>
    <div class="relative">
        <div class="flex gap-2">
            <input
                type="text" x-model="query" x-on:keydown.enter.prevent="search()"
                placeholder="{{ __('Buscar una dirección…') }}"
                class="block w-full flex-1 rounded-lg border-slate-300 text-sm shadow-soft-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
            />
            <x-ui.button type="button" variant="secondary" size="sm" x-on:click="search()" x-bind:disabled="searching">
                <span x-show="! searching">{{ __('Buscar') }}</span>
                <span x-show="searching" x-cloak>{{ __('Buscando…') }}</span>
            </x-ui.button>
        </div>

        <ul
            x-show="results.length > 0" x-cloak
            class="absolute z-10 mt-1 max-h-48 w-full overflow-y-auto themed-scrollbar rounded-lg border border-slate-200 bg-white text-sm shadow-soft dark:border-slate-700 dark:bg-slate-800"
        >
            <template x-for="(result, index) in results" :key="index">
                <li
                    x-on:click="selectResult(result)" x-text="result.label"
                    class="cursor-pointer px-3 py-2 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-700"
                ></li>
            </template>
        </ul>
        <p x-show="searched && results.length === 0 && ! searching" x-cloak class="mt-1 text-xs text-slate-400">
            {{ __('Sin resultados.') }}
        </p>
    </div>

    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
        {{ __('Arrastra el pin verde para fijar el punto y el ámbar para ajustar el radio, toca el mapa, o escribe los valores a mano — van a la vez.') }}
    </p>

    <div x-ref="map" class="mt-2 h-64 w-full overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700"></div>

    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div>
            <label class="text-xs text-slate-500 dark:text-slate-400">{{ __('Latitud') }}</label>
            <input
                type="text" inputmode="decimal" x-model.number="lat" x-on:change="applyLatLngInput()"
                class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
            />
        </div>
        <div>
            <label class="text-xs text-slate-500 dark:text-slate-400">{{ __('Longitud') }}</label>
            <input
                type="text" inputmode="decimal" x-model.number="lng" x-on:change="applyLatLngInput()"
                class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
            />
        </div>
        <div>
            <label class="text-xs text-slate-500 dark:text-slate-400">{{ __('Radio (metros)') }}</label>
            <input
                type="number" min="10" x-model.number="radius" x-on:change="applyRadiusInput()"
                class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-soft-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
            />
        </div>
    </div>
</div>
