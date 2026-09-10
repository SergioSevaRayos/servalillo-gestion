@props(['route']) {{-- RouteDay | null (`$this->statusRoute` del componente que lo incluye) --}}

<x-modal name="route-status" max-width="sm">
    @if ($route)
        <div class="p-6">
            <h3 class="text-lg font-medium text-slate-900 dark:text-white">{{ __('Estado de la ruta') }}</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $route->truck->code }} · {{ $route->route_date->format('d/m/Y') }}
            </p>
            <p class="mt-3 text-xs text-slate-400">
                {{ __('Si sale de "Completada" se deshace el cierre de jornada: el contador de litros del camión vuelve a la lectura de inicio.') }}
            </p>

            <div class="mt-4 space-y-1.5">
                @foreach (\App\Enums\RouteStatus::cases() as $s)
                    <button type="button"
                        wire:click="setStatus({{ $route->id }}, '{{ $s->value }}')"
                        @disabled($route->status === $s)
                        @class([
                            'flex w-full items-center justify-between rounded-lg border px-3 py-2 text-sm transition',
                            'border-primary-400 bg-primary-50 font-semibold text-primary-700 dark:border-primary-500 dark:bg-primary-500/10 dark:text-primary-300' => $route->status === $s,
                            'border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800' => $route->status !== $s,
                        ])>
                        <span>{{ $s->label() }}</span>
                        @if ($route->status === $s)
                            <span class="text-xs font-normal">{{ __('actual') }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="mt-5 flex justify-end">
                <x-ui.button variant="secondary" type="button" x-on:click="$dispatch('close')">{{ __('Cerrar') }}</x-ui.button>
            </div>
        </div>
    @endif
</x-modal>
