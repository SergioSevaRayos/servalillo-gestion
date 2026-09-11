@props(['routeDay', 'drivers']) {{-- RouteDay|null, Collection<Driver> --}}

<x-modal name="route-reassign-driver" max-width="sm">
    @if ($routeDay)
        <form wire:submit="reassignDriver" class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Reasignar chofer del día') }}</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $routeDay->route_date->format('d/m/Y') }} —
                {{ __('actualmente: :driver', ['driver' => $routeDay->driver?->user?->name ?? __('sin chofer')]) }}
            </p>

            <div class="mt-4">
                <x-ui.select name="reassignDriverId" label="{{ __('Nuevo chofer') }}" wire:model="reassignDriverId">
                    @foreach ($drivers as $driver)
                        <option value="{{ $driver->id }}">{{ $driver->user->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="mt-3">
                <x-ui.checkbox name="reassignDeviceToo" label="{{ __('Reasignar también el dispositivo GPS del camión') }}" wire:model="reassignDeviceToo" />
                <p class="mt-1 text-xs text-slate-400">
                    {{ __('Si el chofer que sale tenía un dispositivo tracker, pasa a ser del nuevo — así el recorrido y la velocidad de hoy salen bien. No se toca si el nuevo chofer ya tiene su propio dispositivo.') }}
                </p>
            </div>

            <div class="mt-5 flex justify-end gap-3">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cancelar') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Reasignar') }}</x-ui.button>
            </div>
        </form>
    @endif
</x-modal>
