@props(['stops'])

{{-- "Ruta eficiente": antes de reordenar, se pregunta desde dónde sale el camión.
     - "Desde la base": usa las coordenadas de la base (config servalillo.base).
     - "Desde un cliente": se elige una parada pendiente y se optimiza el resto desde ahí.
     Los dos botones llaman a runOptimize(...) — el mismo método existe en el tablero y en el chofer. --}}
<x-modal name="route-optimize" max-width="lg">
    <div class="p-6"
        x-data="{ step: 'origin' }"
        x-on:open-modal.window="$event.detail == 'route-optimize' && (step = 'origin')">
        <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Ruta eficiente') }}</h3>

        {{-- Paso 1: elegir el tipo de origen --}}
        <div x-show="step === 'origin'" class="mt-4 space-y-2.5">
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('¿Desde dónde sale el camión?') }}</p>

            <button type="button" wire:click="runOptimize('base')" wire:target="runOptimize" wire:loading.attr="disabled"
                class="flex w-full items-center gap-3 rounded-xl border border-slate-200 p-4 text-left transition-colors hover:border-primary-400 hover:bg-primary-50/60 disabled:opacity-50 dark:border-slate-700 dark:hover:border-primary-500 dark:hover:bg-primary-500/10">
                <svg class="h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                </svg>
                <span>
                    <span class="block text-sm font-medium text-slate-800 dark:text-slate-100">{{ __('Desde la base') }}</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ __('El camión arranca desde la nave.') }}</span>
                </span>
            </button>

            <button type="button" x-on:click="step = 'client'"
                @disabled($stops->isEmpty())
                class="flex w-full items-center gap-3 rounded-xl border border-slate-200 p-4 text-left transition-colors hover:border-primary-400 hover:bg-primary-50/60 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:hover:border-primary-500 dark:hover:bg-primary-500/10">
                <svg class="h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                </svg>
                <span>
                    <span class="block text-sm font-medium text-slate-800 dark:text-slate-100">{{ __('Desde un cliente') }}</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400">
                        {{ $stops->isEmpty()
                            ? __('No hay paradas pendientes con ubicación.')
                            : __('El camión ya está en una parada de la ruta.') }}
                    </span>
                </span>
            </button>
        </div>

        {{-- Paso 2: elegir la parada de partida --}}
        <div x-show="step === 'client'" x-cloak class="mt-4">
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('¿En qué parada está el camión ahora?') }}</p>

            <ul class="mt-2 max-h-[45vh] space-y-1.5 overflow-y-auto themed-scrollbar">
                @foreach ($stops as $stop)
                    <li>
                        <button type="button" wire:click="runOptimize('{{ $stop->id }}')"
                            wire:target="runOptimize" wire:loading.attr="disabled"
                            class="flex w-full flex-col rounded-lg border border-slate-200 p-3 text-left transition-colors hover:border-primary-400 hover:bg-primary-50/60 disabled:opacity-50 dark:border-slate-700 dark:hover:border-primary-500 dark:hover:bg-primary-500/10">
                            <span class="text-sm font-medium text-slate-800 dark:text-slate-100">{{ $stop->customer_name }}</span>
                            <span class="text-xs text-slate-500 dark:text-slate-400">{{ $stop->address ?: '—' }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>

            <button type="button" x-on:click="step = 'origin'"
                class="mt-3 text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                &larr; {{ __('Volver') }}
            </button>
        </div>

        <div class="mt-5 flex items-center justify-between gap-3">
            <span wire:loading wire:target="runOptimize" class="text-sm text-slate-500 dark:text-slate-400">
                {{ __('Calculando la ruta más corta…') }}
            </span>
            <x-ui.button variant="secondary" type="button" class="ml-auto" x-on:click="$dispatch('close')">
                {{ __('Cancelar') }}
            </x-ui.button>
        </div>
    </div>
</x-modal>
