<div>
    <x-maintenance.tabs />

    <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
        {{ __('Ajustes generales, editables sin tocar el servidor ni desplegar.') }}
    </p>

    <form wire:submit="save" class="max-w-2xl space-y-6">
        <x-ui.card>
            <h3 class="text-base font-medium text-slate-800 dark:text-slate-100">{{ __('Ubicación de la base (empresa)') }}</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Punto de partida de "Ruta eficiente" (Desde la base), geovalla por defecto de fichaje, y punto que se excluye al detectar paradas no programadas.') }}
            </p>

            <div class="mt-4">
                <x-ui.geofence-map
                    lat-path="base_latitude"
                    lng-path="base_longitude"
                    search-method="searchAddress"
                    :lat="(float) $base_latitude"
                    :lng="(float) $base_longitude"
                    :base-lat="(float) $base_latitude"
                    :base-lng="(float) $base_longitude"
                    :show-radius="false"
                />
            </div>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-base font-medium text-slate-800 dark:text-slate-100">{{ __('Paradas no programadas') }}</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Cuánto tiempo tiene que estar el camión parado fuera de una parada de la ruta y de la base para que quede anotado como "no programada" (solo lo ve administración/mantenimiento, en "Ver detalle" y "Ver recorrido").') }}
            </p>

            <div class="mt-4 max-w-xs">
                <x-ui.input
                    type="number" min="1" max="120" step="1"
                    name="unplanned_stop_minutes"
                    label="{{ __('Minutos') }}"
                    wire:model="unplanned_stop_minutes"
                />
            </div>
        </x-ui.card>

        <div class="flex justify-end">
            <x-ui.button type="submit">{{ __('Guardar ajustes') }}</x-ui.button>
        </div>
    </form>
</div>
