<x-app-layout>
    <x-slot name="header">{{ __('Mi ruta de hoy') }}</x-slot>

    {{-- Web operativa del chofer: siempre superficie sólida y alto contraste (uso al aire libre). --}}
    <x-ui.card>
        <p class="text-slate-600 dark:text-slate-300">
            {{ __('La operativa del chofer (paradas, firma, contador, albarán) se implementa en el Bloque 7.') }}
        </p>
    </x-ui.card>
</x-app-layout>
