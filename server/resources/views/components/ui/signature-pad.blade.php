@props([
    'label' => 'Firma del cliente',
    'syncOn' => null,
])

@php $wireModel = $attributes->wire('model')->value(); @endphp

<div
    x-data="signaturePad({ syncOn: {{ $syncOn ? "'".$syncOn."'" : 'null' }} })"
    @if ($wireModel) x-modelable="value" wire:model="{{ $wireModel }}" @endif
>
    <div class="flex items-center justify-between">
        <x-input-label :value="$label" />
        <button type="button" x-on:click="clear()" class="text-xs font-medium text-slate-500 hover:text-rose-600 dark:text-slate-400">
            {{ __('Borrar') }}
        </button>
    </div>

    <div class="mt-1 rounded-lg border border-slate-300 bg-white dark:border-slate-700 dark:bg-slate-100">
        {{-- Fondo claro siempre: la firma se guarda como PNG y va a un PDF con fondo blanco. --}}
        <canvas x-ref="canvas" class="h-40 w-full touch-none" style="--sig-ink:#0f172a;"></canvas>
    </div>

    <p class="mt-1 text-xs text-slate-400" x-show="!value">{{ __('Firma con el dedo en el recuadro.') }}</p>

    @if ($wireModel)
        @error($wireModel)
            <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
        @enderror
    @endif
</div>
