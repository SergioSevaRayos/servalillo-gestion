<div wire:poll.30s="refreshMap" x-data="{ focusId: null }">
    @php
        $fleet = $this->fleet;
        $fresh = collect($fleet)->where('state', 'fresh')->count();
        $stale = collect($fleet)->whereIn('state', ['stale', 'none'])->count();
    @endphp

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ __('Mapa de la flota') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Última posición de cada camión. Se actualiza solo cada 30 segundos.') }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <x-ui.badge variant="success">{{ trans_choice(':count en línea|:count en línea', $fresh) }}</x-ui.badge>
            @if ($stale > 0)
                <x-ui.badge variant="warning">{{ trans_choice(':count sin señal|:count sin señal', $stale) }}</x-ui.badge>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {{-- wire:ignore: el mapa vive en Alpine; el poll lo actualiza con el evento fleet-map-updated. --}}
        <div class="lg:col-span-2" wire:ignore
            x-data="fleetMap(@js($fleet))"
            x-on:fleet-map-updated.window="update($event.detail.fleet)"
            x-on:fleet-focus.window="focus($event.detail.id)">
            <div class="relative">
                <div x-ref="map" class="h-[60vh] w-full overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 lg:h-[calc(100vh-15rem)]"></div>
                <button type="button" x-on:click="fit()"
                    class="absolute right-3 top-3 z-[500] rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-soft-sm ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700">
                    {{ __('Centrar flota') }}
                </button>
            </div>
        </div>

        <div class="space-y-2">
            @forelse ($fleet as $d)
                <button type="button" wire:key="fleet-{{ $d['id'] }}"
                    x-on:click="$dispatch('fleet-focus', { id: {{ $d['id'] }} })"
                    class="surface w-full rounded-xl p-3 text-left transition-colors hover:bg-slate-50 dark:hover:bg-slate-800">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-medium text-slate-900 dark:text-white">{{ $d['driver'] }}</span>
                        <x-ui.badge :variant="['fresh' => 'success', 'stale' => 'warning', 'none' => 'neutral'][$d['state']]">
                            {{ ['fresh' => __('En línea'), 'stale' => __('Sin señal'), 'none' => __('Sin datos')][$d['state']] }}
                        </x-ui.badge>
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        @if ($d['truck']) {{ $d['truck'] }} · @endif
                        @if ($d['route_status']) {{ $d['route_status'] }} · @else {{ __('Sin ruta hoy') }} · @endif
                        @if ($d['minutes_ago'] !== null)
                            {{ $d['minutes_ago'] < 1 ? __('ahora mismo') : __('hace :n min', ['n' => $d['minutes_ago']]) }}
                        @else
                            {{ __('nunca ha enviado posición') }}
                        @endif
                        @if ($d['speed_kmh'] !== null) · {{ $d['speed_kmh'] }} km/h @endif
                        @if ($d['battery'] !== null) · {{ __('batería') }} {{ $d['battery'] }}% @endif
                    </p>
                </button>
            @empty
                <x-ui.empty-state title="{{ __('Sin dispositivos') }}" description="{{ __('Aparecerán aquí los móviles con la APK tracker que tengan un chofer asignado.') }}" />
            @endforelse
        </div>
    </div>
</div>
