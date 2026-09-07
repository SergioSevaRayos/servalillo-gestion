@props([
    'digits' => 7,
    'value' => 0,
    // Unidad que se muestra bajo la ruleta (ej. 'km', 'L'). Cadena vacía = sin unidad.
    'unit' => '',
    // Nombre del modal (evento open-modal) tras el cual hay que re-leer el valor del servidor.
    'syncOn' => null,
])

@php
    $wireModel = $attributes->wire('model')->value();
    $start = max(0, (int) $value);
@endphp

<div
    x-data="digitWheel({ count: {{ (int) $digits }}, initial: {{ $start }}, model: {{ $wireModel ? "'".$wireModel."'" : 'null' }} })"
    @if ($wireModel) x-modelable="value" wire:model="{{ $wireModel }}" @endif
    {{-- Livewire manda el nombre del modal como detalle (a veces envuelto en array); == lo compara igual que <x-modal>. --}}
    @if ($syncOn) x-on:open-modal.window="$event.detail == '{{ $syncOn }}' && resync()" @endif
    class="select-none"
>
    <div class="digit-wheel mx-auto">
        <div class="digit-wheel__band"></div>
        <div class="flex gap-1" x-ref="cols">
            <template x-for="c in count" :key="c">
                <div class="digit-wheel__col themed-scrollbar" data-col x-on:scroll.passive.debounce.100ms="onScroll">
                    <div class="h-11 shrink-0"></div>
                    <template x-for="d in 10" :key="d">
                        <div class="digit-wheel__item" x-text="d - 1"></div>
                    </template>
                    <div class="h-11 shrink-0"></div>
                </div>
            </template>
        </div>
    </div>

    <p class="mt-2 text-center text-sm tabular-nums text-slate-500 dark:text-slate-400">
        <span x-text="Number(value).toLocaleString('es-ES')"></span>@if ($unit) {{ $unit }}@endif
    </p>

    @if ($wireModel)
        @error($wireModel)
            <p class="mt-1 text-center text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
        @enderror
    @endif
</div>
